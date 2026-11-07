<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaleRequest;
use App\Http\Resources\Admin\SaleResource;
use App\Models\LabOrder;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Financial;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Vendas da ótica — a promissória (07/11/2026; admin e atendente, cancelar só o admin). A venda é montada como
 * **rascunho** (cliente que paga e óculos: pedidos com o paciente e o valor, lançados pelo formulário de pedido com
 * `sale_id`); **"Lançar venda"** registra a entrada e gera o título a receber no Financeiro. Mudanças depois de lançar
 * (óculos, valores, pagamentos) ajustam o Financeiro sozinhas, até algum óculos ir ao laboratório (aí óculos e valores
 * travam). **"Entregar ao cliente"** recebe o restante. Cancelar: só até enviar ao laboratório.
 */
class SaleController extends Controller
{
    public function __construct(private Financial $financial, private CurrentCompany $current) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'city_visit_id' => ['nullable', 'integer'],
            // Só as da loja (sem atendimento).
            'standalone' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $sales = Sale::query()
            ->with(self::RELATIONS)
            ->when($filters['city_visit_id'] ?? null, fn ($q, int $id) => $q->where('city_visit_id', $id))
            ->when($request->boolean('standalone'), fn ($q) => $q->whereNull('city_visit_id'))
            ->when(trim($filters['search'] ?? ''), function ($q, string $search) {
                $digits = preg_replace('/\D/', '', $search);
                $q->where(fn ($q) => $q->whereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%")->when($digits !== '', fn ($q) => $q->orWhere('cpf', 'like', "{$digits}%")))
                    ->when($digits !== '', fn ($q) => $q->orWhere('id', (int) $digits)));
            })
            ->latest('id')
            ->paginate(25);

        return SaleResource::collection($sales);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load(self::RELATIONS));
    }

    /**
     * Nova venda (rascunho): o cliente que paga, o atendimento (vazio = da loja) e a entrega prevista.
     */
    public function store(SaleRequest $request): JsonResponse
    {
        $sale = Sale::create([...$request->safe()->only(['client_id', 'city_visit_id', 'expected_delivery_date', 'notes']), 'user_id' => $request->user()->id]);

        return $this->show($sale)->response()->setStatusCode(201);
    }

    /**
     * Troca o cliente que paga, a entrega prevista ou as observações (o atendimento não troca).
     */
    public function update(SaleRequest $request, Sale $sale): SaleResource
    {
        $this->assertOpen($sale, items: false);

        return $this->change($sale, fn () => $sale->update($request->safe()->only(['client_id', 'expected_delivery_date', 'notes'])));
    }

    /**
     * Pagamento antes da entrega (entrada). Não passa do restante.
     */
    public function addPayment(Request $request, Sale $sale): SaleResource
    {
        $this->assertOpen($sale, items: false);
        $line = $this->validatePayment($request->all(), '');
        if ($this->cents($line['amount']) > $sale->load(self::RELATIONS)->remainingCents()) {
            throw ValidationException::withMessages(['amount' => 'O pagamento passa do que falta pagar.']);
        }

        return $this->change($sale, fn () => $sale->payments()->create([...$line, 'stage' => 'down', 'paid_on' => today(), 'user_id' => $request->user()->id]));
    }

    public function removePayment(Sale $sale, SalePayment $payment): SaleResource
    {
        $this->assertOpen($sale, items: false);
        abort_unless($payment->sale_id === $sale->id, 404);

        return $this->change($sale, fn () => $payment->delete());
    }

    /**
     * "Lançar venda": registra a entrada (uma ou mais formas; pode vir vazia, se não pagou nada agora) e gera o título a
     * receber no Financeiro. Precisa de pelo menos um óculos com valor e do cliente com CPF e telefone.
     */
    public function launch(Request $request, Sale $sale): SaleResource
    {
        $sale->load(self::RELATIONS);
        if ($sale->status() !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Esta venda já foi lançada.']);
        }
        $lines = $this->validatePayments($request);
        if ($sale->activeOrders()->isEmpty()) {
            throw ValidationException::withMessages(['orders' => 'Adicione pelo menos um óculos à venda.']);
        }
        if ($sale->activeOrders()->contains(fn (LabOrder $order) => (float) $order->price <= 0)) {
            throw ValidationException::withMessages(['orders' => 'Informe o valor de todos os óculos.']);
        }
        if (! $sale->client->cpf || ! $sale->client->phone_number) {
            throw ValidationException::withMessages(['client_id' => 'Complete o cadastro do cliente que paga: CPF e telefone.']);
        }
        if (collect($lines)->sum(fn ($line) => $this->cents($line['amount'])) > $sale->totalCents()) {
            throw ValidationException::withMessages(['payments' => 'A entrada passa do valor da venda.']);
        }

        return $this->change($sale, function () use ($sale, $lines, $request) {
            foreach ($lines as $line) {
                $sale->payments()->create([...$line, 'stage' => 'down', 'paid_on' => today(), 'user_id' => $request->user()->id]);
            }
            $sale->update(['launched_at' => now()]);
        }, force: true);
    }

    /**
     * "Entregar ao cliente": recebe o restante (uma ou mais formas; a soma tem de ser o que falta).
     */
    public function deliver(Request $request, Sale $sale): SaleResource
    {
        $sale->load(self::RELATIONS);
        if ($sale->status() !== 'launched') {
            throw ValidationException::withMessages(['status' => $sale->status() === 'draft' ? 'Lance a venda antes de entregar.' : 'Esta venda não pode ser entregue.']);
        }
        $lines = $this->validatePayments($request);
        $paying = collect($lines)->sum(fn ($line) => $this->cents($line['amount']));
        if ($paying !== $sale->remainingCents()) {
            throw ValidationException::withMessages(['payments' => 'Na entrega, receba o que falta: '.number_format($sale->remainingCents() / 100, 2, ',', '.').'.']);
        }

        return $this->change($sale, function () use ($sale, $lines, $request) {
            foreach ($lines as $line) {
                $sale->payments()->create([...$line, 'stage' => 'delivery', 'paid_on' => today(), 'user_id' => $request->user()->id]);
            }
            $sale->update(['delivered_at' => now()]);
        });
    }

    /**
     * Cancela (só o admin), só enquanto nenhum óculos foi ao laboratório: os pedidos pendentes também e, se lançada, o
     * título no Financeiro.
     */
    public function cancel(Request $request, Sale $sale): SaleResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:200']], ['reason.required' => 'Informe o motivo do cancelamento.'])['reason'];
        $sale->load(self::RELATIONS);
        if ($sale->canceled_at || $sale->delivered_at) {
            throw ValidationException::withMessages(['status' => 'Esta venda não pode ser cancelada.']);
        }
        if ($sale->locked()) {
            throw ValidationException::withMessages(['status' => 'Algum óculos desta venda já foi ao laboratório: não dá para cancelar.']);
        }

        return $this->run(function () use ($sale, $reason) {
            DB::transaction(function () use ($sale, $reason) {
                foreach ($sale->activeOrders() as $order) {
                    $order->update(['status' => 'canceled', 'status_label' => 'Cancelada', 'cancel_reason' => "Venda cancelada: {$reason}", 'status_at' => now()]);
                }
                $sale->update(['canceled_at' => now(), 'cancel_reason' => $reason]);
                if ($sale->launched_at) {
                    $this->financial->cancel($sale, $reason);
                }
            });

            return $this->show($sale);
        });
    }

    /**
     * Configurações das vendas (admin): a conta do Financeiro de cada forma de pagamento.
     */
    public function settings(): JsonResponse
    {
        $company = $this->current->require();
        $connected = $this->financial->connected($company);
        $accounts = [];
        $error = null;
        if ($connected) {
            try {
                $accounts = $this->financial->accounts($company);
            } catch (RuntimeException|ValidationException $failure) {
                $error = $failure->getMessage();
            }
        }

        return response()->json(['data' => [
            'connected' => $connected,
            'accounts' => $accounts,
            'error' => $error,
            'sale_accounts' => (object) ($company->sale_accounts ?? []),
            'methods' => collect(SalePayment::METHODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $company = $this->current->require();
        $data = $request->validate([
            'sale_accounts' => ['required', 'array'],
            'sale_accounts.cash' => ['nullable', 'integer'],
            'sale_accounts.pix' => ['nullable', 'integer'],
            'sale_accounts.card' => ['nullable', 'integer'],
        ]);
        $valid = collect($this->run(fn () => $this->financial->accounts($company)))->pluck('id')->all();
        foreach ($data['sale_accounts'] as $method => $account) {
            if ($account !== null && ! in_array((int) $account, $valid, true)) {
                throw ValidationException::withMessages(["sale_accounts.{$method}" => 'Conta não encontrada no Financeiro.']);
            }
        }
        $company->update(['sale_accounts' => collect($data['sale_accounts'])->only(array_keys(SalePayment::METHODS))->filter()->map(fn ($id) => (int) $id)->all()]);

        return $this->settings();
    }

    public const RELATIONS = ['client.city', 'cityVisit.city', 'orders.client', 'payments', 'user'];

    /**
     * Venda que ainda aceita mudanças. `items` = óculos e valores (travam quando algum óculos foi ao laboratório).
     */
    public static function assertOpen(Sale $sale, bool $items = true): void
    {
        $sale->loadMissing('orders');
        if ($sale->canceled_at) {
            throw ValidationException::withMessages(['sale_id' => 'Venda cancelada.']);
        }
        if ($sale->delivered_at) {
            throw ValidationException::withMessages(['sale_id' => 'Venda já entregue ao cliente.']);
        }
        if ($items && $sale->locked()) {
            throw ValidationException::withMessages(['sale_id' => 'Algum óculos desta venda já foi ao laboratório: óculos e valores não mudam mais.']);
        }
    }

    /**
     * Aplica a mudança e, se a venda está lançada, ajusta o Financeiro (tudo ou nada: se o Financeiro recusar, desfaz).
     */
    private function change(Sale $sale, callable $action, bool $force = false): SaleResource
    {
        return $this->run(function () use ($sale, $action, $force) {
            DB::transaction(function () use ($sale, $action, $force) {
                $action();
                $sale->refresh()->load(self::RELATIONS);
                if ($force || $sale->launched_at) {
                    $this->financial->sync($sale);
                }
            });

            return $this->show($sale->refresh());
        });
    }

    private function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['financial' => $error->getMessage()]);
        }
    }

    /**
     * @return list<array{method: string, amount: string, installments: int|null}>
     */
    private function validatePayments(Request $request): array
    {
        $data = $request->validate(['payments' => ['present', 'array', 'max:10']], ['payments.present' => 'Informe os pagamentos (pode ser nenhum).']);

        return collect($data['payments'])->map(fn ($line, $index) => $this->validatePayment((array) $line, "payments.{$index}."))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{method: string, amount: string, installments: int|null}
     */
    private function validatePayment(array $line, string $prefix): array
    {
        $data = validator($line, [
            'method' => ['required', Rule::in(array_keys(SalePayment::METHODS))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'installments' => ['nullable', 'integer', 'between:1,24'],
        ], [
            'method.required' => 'Escolha a forma de pagamento.',
            'amount.gt' => 'Informe o valor.',
        ])->validate();

        return [
            'method' => $data['method'],
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'installments' => $data['method'] === 'card' ? ($data['installments'] ?? 1) : null,
        ];
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
