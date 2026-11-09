<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaleRequest;
use App\Http\Resources\Admin\SaleResource;
use App\Models\CityVisit;
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
            // A tela do atendimento mostra as vendas com os óculos de cada uma, sem paginar (até 100).
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            ->paginate($filters['per_page'] ?? 25);
        // Baixas feitas no Financeiro entram nas fichas da página (09/11/2026).
        if ($this->financial->pull($sales->getCollection())) {
            $sales->getCollection()->each->load('payments');
        }

        return SaleResource::collection($sales);
    }

    public function show(Sale $sale): SaleResource
    {
        $this->financial->pull([$sale]);

        return new SaleResource($sale->load(self::RELATIONS));
    }

    /**
     * Nova venda (rascunho): o cliente que paga, o atendimento (vazio = da loja) e a entrega prevista.
     */
    public function store(SaleRequest $request): JsonResponse
    {
        $data = $request->safe()->only(['client_id', 'city_visit_id', 'expected_delivery_date', 'notes']);
        // Ficha do atendimento: nasce com a entrega prevista do atendimento.
        if ($data['city_visit_id'] ?? null) {
            $data['expected_delivery_date'] = CityVisit::findOrFail($data['city_visit_id'])->deliveryDate()->toDateString();
        }
        $sale = Sale::create([...$data, 'user_id' => $request->user()->id]);

        return $this->show($sale)->response()->setStatusCode(201);
    }

    /**
     * Troca o cliente que paga, a entrega prevista ou as observações (o atendimento não troca).
     */
    public function update(SaleRequest $request, Sale $sale): SaleResource
    {
        $this->assertOpen($sale, items: false);

        // Ficha do atendimento: a entrega prevista é a do atendimento (troca-se lá, para todas).
        $fields = $sale->city_visit_id ? ['client_id', 'notes'] : ['client_id', 'expected_delivery_date', 'notes'];

        return $this->change($sale, fn () => $sale->update($request->safe()->only($fields)));
    }

    /**
     * Relatório das fichas de um atendimento (09/11/2026; admin e atendente): sempre de um atendimento só, sem misturar.
     * Lista todas as fichas (rascunho e canceladas também) com o que foi pago e como, e o que fica para receber na entrega.
     * Os totais não contam as canceladas; "a receber na entrega" é o que falta das lançadas, e o dos rascunhos vem à parte
     * (ainda não lançados).
     */
    public function visitReport(CityVisit $cityVisit): JsonResponse
    {
        $cityVisit->load('city');
        $sales = Sale::query()->where('city_visit_id', $cityVisit->id)->with(['client', 'orders', 'payments'])->orderBy('id')->get();
        // Baixas feitas no Financeiro entram antes de somar (09/11/2026).
        if ($this->financial->pull($sales)) {
            $sales->each->load('payments');
        }
        $money = fn (int $cents) => number_format($cents / 100, 2, '.', '');
        $cents = fn ($amount) => (int) round(((float) $amount) * 100);

        $totals = ['total' => 0, 'paid' => 0, 'remaining' => 0, 'draft' => 0, 'down' => 0, 'delivery' => 0];
        $methods = array_fill_keys(array_keys(SalePayment::METHODS), 0);
        $count = ['draft' => 0, 'launched' => 0, 'delivered' => 0, 'canceled' => 0];
        $rows = [];
        foreach ($sales as $sale) {
            $status = $sale->status();
            $count[$status]++;
            if ($status !== 'canceled') {
                $totals['total'] += $sale->totalCents();
                $totals['paid'] += $sale->paidCents();
                if ($status === 'launched') {
                    $totals['remaining'] += $sale->remainingCents();
                }
                if ($status === 'draft') {
                    $totals['draft'] += $sale->totalCents();
                }
                foreach ($sale->payments as $payment) {
                    $methods[$payment->method] = ($methods[$payment->method] ?? 0) + $cents($payment->amount);
                    $totals[$payment->stage === 'delivery' ? 'delivery' : 'down'] += $cents($payment->amount);
                }
            }
            $rows[] = [
                'id' => $sale->id,
                'status' => $status,
                'client' => $sale->client ? ['id' => $sale->client->id, 'name' => $sale->client->name, 'cpf' => $sale->client->cpf] : null,
                'glasses' => $sale->activeOrders()->count(),
                'total' => $money($sale->totalCents()),
                'paid' => $money($sale->paidCents()),
                // Na entrega: o que falta das lançadas (entregue = 0; rascunho e cancelada não têm).
                'remaining' => $money($status === 'launched' ? $sale->remainingCents() : 0),
                'payments' => $sale->payments->map(fn (SalePayment $payment) => [
                    'stage' => $payment->stage,
                    'method' => $payment->method,
                    'method_label' => SalePayment::METHODS[$payment->method] ?? $payment->method,
                    'amount' => $payment->amount,
                    'installments' => $payment->installments,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'from_financial' => $payment->from_financial,
                ])->values(),
                'settled' => $status === 'launched' && $sale->totalCents() > 0 && $sale->remainingCents() === 0,
                'launched_at' => $sale->launched_at?->toIso8601String(),
                'delivered_at' => $sale->delivered_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => [
            'visit' => [
                'id' => $cityVisit->id,
                'title' => $cityVisit->title,
                'city' => $cityVisit->city?->name,
                'visit_date' => $cityVisit->visit_date->toDateString(),
                'end_date' => $cityVisit->end_date?->toDateString(),
                'expected_delivery_date' => $cityVisit->deliveryDate()->toDateString(),
            ],
            'count' => $count,
            'totals' => [
                ...array_map($money, $totals),
                'methods' => collect($methods)->map(fn (int $value, string $method) => ['method' => $method, 'method_label' => SalePayment::METHODS[$method] ?? $method, 'amount' => $money($value)])->values(),
            ],
            'sales' => $rows,
        ]]);
    }

    /**
     * Troca a entrega prevista do atendimento (09/11/2026) e de todas as fichas dele (menos canceladas e entregues); nas
     * lançadas, o vencimento do restante no Financeiro acompanha. Ficha que o Financeiro recusar continua com a data
     * anterior e volta em `failed` com o motivo.
     */
    public function visitDelivery(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $date = $request->validate(['expected_delivery_date' => ['required', 'date']], ['expected_delivery_date.required' => 'Informe a entrega prevista.'])['expected_delivery_date'];
        $cityVisit->update(['expected_delivery_date' => $date]);

        $failed = [];
        $sales = Sale::where('city_visit_id', $cityVisit->id)->whereNull('canceled_at')->whereNull('delivered_at')->get();
        foreach ($sales as $sale) {
            try {
                $this->change($sale, fn () => $sale->update(['expected_delivery_date' => $date]));
            } catch (ValidationException $error) {
                $failed[] = ['id' => $sale->id, 'error' => collect($error->errors())->flatten()->first()];
            }
        }

        return response()->json(['data' => [
            'expected_delivery_date' => $cityVisit->deliveryDate()->toDateString(),
            'updated' => $sales->count() - count($failed),
            'failed' => $failed,
        ]]);
    }

    /**
     * Inclui na ficha uma OS do mesmo atendimento que está sem ficha (09/11/2026: as lançadas antes da ficha existir),
     * com o valor do óculos. Vale mesmo já enviada ao laboratório (é a exceção à trava); na ficha lançada, o Financeiro
     * acompanha. Ficha da loja, cancelada ou entregue não recebe.
     */
    public function attachOrder(Request $request, Sale $sale): SaleResource
    {
        $data = $request->validate([
            'lab_order_id' => ['required', 'integer'],
            'price' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
        ], [
            'lab_order_id.required' => 'Escolha a OS.',
            'price.required' => 'Informe o valor do óculos.',
            'price.gt' => 'Informe o valor do óculos.',
        ]);
        $this->assertOpen($sale, items: false);
        if (! $sale->city_visit_id) {
            throw ValidationException::withMessages(['lab_order_id' => 'Só a ficha de um atendimento recebe OS sem ficha.']);
        }
        $order = LabOrder::whereKey($data['lab_order_id'])->where('city_visit_id', $sale->city_visit_id)->whereNull('sale_id')->first();
        if (! $order || $order->status === 'canceled') {
            throw ValidationException::withMessages(['lab_order_id' => 'Escolha uma OS deste atendimento que esteja sem ficha (e não cancelada).']);
        }

        return $this->change($sale, fn () => $order->update(['sale_id' => $sale->id, 'price' => $data['price']]));
    }

    /**
     * Pagamento antes da entrega (entrada). Não passa do restante.
     */
    public function addPayment(Request $request, Sale $sale): SaleResource
    {
        $this->assertOpen($sale, items: false);
        $line = $this->validatePayment($request->all(), '');
        $this->pullStrict($sale);
        if ($this->cents($line['amount']) > $sale->load(self::RELATIONS)->remainingCents()) {
            throw ValidationException::withMessages(['amount' => 'O pagamento passa do que falta pagar.']);
        }

        return $this->change($sale, fn () => $sale->payments()->create([...$line, 'stage' => 'down', 'paid_on' => today(), 'user_id' => $request->user()->id]));
    }

    public function removePayment(Sale $sale, SalePayment $payment): SaleResource
    {
        $this->assertOpen($sale, items: false);
        abort_unless($payment->sale_id === $sale->id, 404);
        if ($payment->financial_payment_id) {
            throw ValidationException::withMessages(['payment' => 'Baixa feita no Financeiro: para desfazer, estorne lá.']);
        }

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
            throw ValidationException::withMessages(['status' => 'Esta ficha já foi lançada.']);
        }
        $lines = $this->validatePayments($request);
        if ($sale->activeOrders()->isEmpty()) {
            throw ValidationException::withMessages(['orders' => 'Adicione pelo menos um óculos à ficha.']);
        }
        if ($sale->activeOrders()->contains(fn (LabOrder $order) => (float) $order->price <= 0)) {
            throw ValidationException::withMessages(['orders' => 'Informe o valor de todos os óculos.']);
        }
        if (! $sale->client->cpf || ! $sale->client->phone_number) {
            throw ValidationException::withMessages(['client_id' => 'Complete o cadastro do cliente que paga: CPF e telefone.']);
        }
        if (collect($lines)->sum(fn ($line) => $this->cents($line['amount'])) > $sale->totalCents()) {
            throw ValidationException::withMessages(['payments' => 'A entrada passa do valor da ficha.']);
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
            throw ValidationException::withMessages(['status' => $sale->status() === 'draft' ? 'Lance a ficha antes de entregar.' : 'Esta ficha não pode ser entregue.']);
        }
        $lines = $this->validatePayments($request);
        // O que falta já com as baixas feitas no Financeiro (quitada lá: entrega sem receber nada).
        $this->pullStrict($sale);
        $sale->load(self::RELATIONS);
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
            throw ValidationException::withMessages(['status' => 'Esta ficha não pode ser cancelada.']);
        }
        if ($sale->locked()) {
            throw ValidationException::withMessages(['status' => 'Algum óculos desta ficha já foi ao laboratório: não dá para cancelar.']);
        }

        return $this->run(function () use ($sale, $reason) {
            DB::transaction(function () use ($sale, $reason) {
                foreach ($sale->activeOrders() as $order) {
                    $order->update(['status' => 'canceled', 'status_label' => 'Cancelada', 'cancel_reason' => "Ficha cancelada: {$reason}", 'status_at' => now()]);
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

    public const RELATIONS = ['client.city', 'cityVisit.city', 'orders.client', 'orders.lab', 'payments', 'user'];

    /**
     * Venda que ainda aceita mudanças. `items` = óculos e valores (travam quando algum óculos foi ao laboratório).
     */
    public static function assertOpen(Sale $sale, bool $items = true): void
    {
        $sale->loadMissing('orders');
        if ($sale->canceled_at) {
            throw ValidationException::withMessages(['sale_id' => 'Ficha cancelada.']);
        }
        if ($sale->delivered_at) {
            throw ValidationException::withMessages(['sale_id' => 'Ficha já entregue ao cliente.']);
        }
        if ($items && $sale->locked()) {
            throw ValidationException::withMessages(['sale_id' => 'Algum óculos desta ficha já foi ao laboratório: óculos e valores não mudam mais.']);
        }
    }

    /**
     * Aplica a mudança e, se a venda está lançada, ajusta o Financeiro (tudo ou nada: se o Financeiro recusar, desfaz).
     */
    private function change(Sale $sale, callable $action, bool $force = false): SaleResource
    {
        return $this->run(function () use ($sale, $action, $force) {
            // Antes de mudar, as baixas feitas no Financeiro entram na ficha (senão o ajuste lá seria recusado).
            if ($this->financial->pull([$sale], strict: true)) {
                $sale->load(self::RELATIONS);
            }
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

    /** Puxa as baixas feitas no Financeiro antes de conferir o que falta (falha vira erro na tela). */
    private function pullStrict(Sale $sale): void
    {
        $this->run(fn () => $this->financial->pull([$sale], strict: true));
        $sale->unsetRelation('payments');
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
