<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LabOrderRequest;
use App\Http\Resources\Admin\LabOrderResource;
use App\Models\CityVisit;
use App\Models\LabConnection;
use App\Models\LabOrder;
use App\Models\Sale;
use App\Services\Financial;
use App\Services\Laboratory;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pedidos aos laboratórios do grupo (admin e atendente; passo 3 do roteiro). O pedido é **salvo** e fica "Pendente de
 * envio": confere, edita e só depois envia. **Pedidos por atendimento (07/11/2026):** o pedido de atendimento externo é
 * lançado dentro do atendimento e vai **junto com os outros dele** (`sendVisit`): no laboratório, a OS entra no lote
 * aberto do atendimento ou o laboratório abre um lote ligado a ele. Sozinho, só o **avulso** (atendimento na loja).
 * Enviado, vira OS lá e a situação volta pelo aviso (ou "Atualizar"). Recusa ou falha no envio: o pedido continua
 * pendente, com o erro em `send_error`. Cancelar: enquanto o laboratório não separou as lentes.
 */
class LabOrderController extends Controller
{
    public function __construct(private Laboratory $laboratory, private CurrentCompany $current, private Financial $financial) {}

    /**
     * Laboratórios ligados a esta ótica (para escolher no pedido).
     */
    public function labs(): JsonResponse
    {
        return response()->json(['data' => LabConnection::orderBy('name')->get()->map(fn (LabConnection $lab) => [
            'id' => $lab->id, 'name' => $lab->name, 'connected' => $lab->connected(),
        ])]);
    }

    /**
     * Tratamentos e opções do laboratório escolhido, na hora.
     */
    public function catalog(LabConnection $labConnection): JsonResponse
    {
        return response()->json(['data' => $this->run(fn () => $this->laboratory->catalog($labConnection))]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'lab_connection_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'not_sent' => ['nullable', 'boolean'],
            'city_visit_id' => ['nullable', 'integer'],
            // Só os avulsos (da loja, sem atendimento).
            'standalone' => ['nullable', 'boolean'],
        ]);

        $orders = LabOrder::query()
            ->with(['lab', 'client', 'user', 'cityVisit.city'])
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['lab_connection_id'] ?? null, fn ($q, int $id) => $q->where('lab_connection_id', $id))
            ->when($filters['client_id'] ?? null, fn ($q, int $id) => $q->where('client_id', $id))
            ->when($filters['city_visit_id'] ?? null, fn ($q, int $id) => $q->where('city_visit_id', $id))
            ->when($request->boolean('standalone'), fn ($q) => $q->whereNull('city_visit_id'))
            // Pendentes de envio (salvos, não enviados, não cancelados).
            ->when($request->boolean('not_sent'), fn ($q) => $q->whereNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled')))
            ->when(trim($filters['search'] ?? ''), function ($q, string $search) {
                $number = ltrim(preg_replace('/\D/', '', $search), '0');
                $q->where(fn ($q) => $q->whereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->when($number !== '', fn ($q) => $q->orWhere('id', (int) $number)->orWhere('lab_number', 'like', "%{$number}")));
            })
            ->latest('id')
            ->paginate(25);

        return LabOrderResource::collection($orders);
    }

    public function show(LabOrder $labOrder): LabOrderResource
    {
        return new LabOrderResource($labOrder->load(['lab', 'client', 'user', 'cityVisit.city']));
    }

    /**
     * Salva o pedido (não envia): fica "Pendente de envio".
     */
    public function store(LabOrderRequest $request): JsonResponse
    {
        $fields = $this->fields($request);
        $sale = $this->saleFor($fields);
        $order = $this->withSale($sale, fn () => LabOrder::create([...$fields, 'user_id' => $request->user()->id]));

        return (new LabOrderResource($order->fresh(['lab', 'client', 'user', 'cityVisit.city'])))->response()->setStatusCode(201);
    }

    /**
     * Edita o pedido pendente (mesmas regras do salvar). A receita nova troca a anterior; sem receita nova, fica a anterior.
     */
    public function update(LabOrderRequest $request, LabOrder $labOrder): LabOrderResource
    {
        if (! $labOrder->pending()) {
            throw ValidationException::withMessages(['status' => 'Só o pedido pendente de envio pode ser editado.']);
        }
        $fields = $this->fields($request);
        if ($labOrder->sale_id && (int) $fields['sale_id'] !== $labOrder->sale_id) {
            throw ValidationException::withMessages(['sale_id' => 'O óculos não troca de venda.']);
        }
        $sale = $this->saleFor($fields);
        if ($fields['prescription_path'] === null) {
            unset($fields['prescription_path']);
        } elseif ($labOrder->prescription_path) {
            Storage::disk('local')->delete($labOrder->prescription_path);
        }
        $this->withSale($sale, fn () => $labOrder->update([...$fields, 'send_error' => null]));

        return $this->show($labOrder);
    }

    /**
     * Envia o pedido pendente. Recusa do laboratório (preenchimento) ou falha: continua pendente, com o erro.
     */
    public function send(LabOrder $labOrder): LabOrderResource
    {
        if (! $labOrder->pending()) {
            throw ValidationException::withMessages(['status' => $labOrder->sent_at ? 'Este pedido já foi enviado ao laboratório.' : 'Pedido cancelado.']);
        }
        self::assertStandalone($labOrder);
        $error = $this->trySend($labOrder);
        if ($error !== null) {
            throw ValidationException::withMessages(['connection' => $error]);
        }

        return $this->show($labOrder);
    }

    /**
     * Envia os avulsos pendentes marcados, cada um sozinho. Pedido de atendimento vai pelo atendimento (`sendVisit`).
     */
    public function sendMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ], ['ids.required' => 'Marque os pedidos que vão ser enviados.']);
        $orders = LabOrder::with(['lab', 'client'])->whereIn('id', $data['ids'])->orderBy('id')->get();
        if ($orders->count() !== count($data['ids']) || $orders->contains(fn (LabOrder $order) => ! $order->pending())) {
            throw ValidationException::withMessages(['ids' => 'Marque só pedidos pendentes de envio.']);
        }
        $orders->each(fn (LabOrder $order) => self::assertStandalone($order));

        $results = $orders->map(fn (LabOrder $order) => ['id' => $order->id, 'error' => $this->trySend($order)]);

        return response()->json([
            'data' => [
                'sent' => $results->whereNull('error')->count(),
                'results' => $results->values()->all(),
            ],
        ]);
    }

    /**
     * Atendimentos que recebem pedidos (não cancelados, sem "Encerrar pedidos"; desde que são cadastrados), do mais
     * recente ao mais antigo, com quantos pedidos pendentes, enviados e cancelados têm. Filtro: `city_id`.
     */
    public function visits(Request $request): JsonResponse
    {
        $cityId = $request->validate(['city_id' => ['nullable', 'integer']])['city_id'] ?? null;
        $visits = CityVisit::acceptingOrders()
            ->with('city')
            ->when($cityId, fn ($q, int $id) => $q->where('city_id', $id))
            ->withCount([
                'labOrders as pending_count' => fn ($q) => $q->whereNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled')),
                'labOrders as sent_count' => fn ($q) => $q->whereNotNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled')),
            ])
            ->orderByDesc('visit_date')->orderByDesc('id')
            ->get();

        return response()->json(['data' => $visits->map(fn (CityVisit $visit) => self::visitData($visit))->values()]);
    }

    /**
     * Um atendimento dos pedidos (aceitando ou não), com as contagens.
     */
    public function visit(CityVisit $cityVisit): JsonResponse
    {
        $cityVisit->load('city')->loadCount([
            'labOrders as pending_count' => fn ($q) => $q->whereNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled')),
            'labOrders as sent_count' => fn ($q) => $q->whereNotNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled')),
        ]);

        return response()->json(['data' => self::visitData($cityVisit)]);
    }

    /**
     * Envia ao laboratório **todos** os pedidos pendentes do atendimento, juntos: no laboratório, as OS entram no lote
     * aberto do atendimento ou num lote novo ligado a ele (cada laboratório, o seu). Quem falhar fica pendente, com o erro.
     */
    public function sendVisit(CityVisit $cityVisit): JsonResponse
    {
        $orders = $cityVisit->labOrders()->with(['lab', 'client', 'cityVisit.city'])
            ->whereNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled'))
            ->orderBy('id')->get();
        if ($orders->isEmpty()) {
            throw ValidationException::withMessages(['orders' => 'Este atendimento não tem pedidos pendentes de envio.']);
        }

        $results = $orders->map(fn (LabOrder $order) => ['id' => $order->id, 'error' => $this->trySend($order)]);

        return response()->json([
            'data' => [
                'sent' => $results->whereNull('error')->count(),
                'results' => $results->values()->all(),
            ],
        ]);
    }

    /**
     * "Encerrar pedidos": o atendimento sai dos cards e não recebe mais pedidos. Com pedido pendente, não encerra.
     */
    public function closeOrders(CityVisit $cityVisit): JsonResponse
    {
        $pending = $cityVisit->labOrders()->whereNull('sent_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled'))->count();
        if ($pending > 0) {
            throw ValidationException::withMessages(['orders' => "Este atendimento tem {$pending} pedido(s) pendente(s): envie ou cancele antes de encerrar."]);
        }
        $cityVisit->update(['orders_closed_at' => now()]);

        return $this->visit($cityVisit);
    }

    /**
     * Volta a receber pedidos (desfaz o "Encerrar pedidos").
     */
    public function reopenOrders(CityVisit $cityVisit): JsonResponse
    {
        $cityVisit->update(['orders_closed_at' => null]);

        return $this->visit($cityVisit);
    }

    /**
     * @return array<string, mixed>
     */
    private static function visitData(CityVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'title' => $visit->title,
            'city' => $visit->city ? ['id' => $visit->city->id, 'name' => $visit->city->name, 'state' => $visit->city->state] : null,
            'visit_date' => $visit->visit_date->toDateString(),
            'end_date' => $visit->end_date?->toDateString(),
            'status' => $visit->status->value,
            'accepts_orders' => $visit->acceptsOrders(),
            'orders_closed_at' => $visit->orders_closed_at?->toIso8601String(),
            'pending_count' => (int) $visit->pending_count,
            'sent_count' => (int) $visit->sent_count,
        ];
    }

    /**
     * Sozinho só vai o avulso (da loja); pedido de atendimento vai junto com os outros dele.
     */
    private static function assertStandalone(LabOrder $order): void
    {
        if ($order->city_visit_id !== null) {
            throw ValidationException::withMessages(['status' => 'Pedido de atendimento: envie pelo atendimento, junto com os outros pedidos dele.']);
        }
    }

    /**
     * Manda um pedido; null = foi. Falhou: grava o erro no pedido (continua pendente) e devolve a mensagem.
     */
    private function trySend(LabOrder $order): ?string
    {
        // Óculos de venda vai ao laboratório só com a venda lançada (cliente que paga e entrada; 07/11/2026).
        if ($order->sale_id && ! Sale::whereKey($order->sale_id)->whereNotNull('launched_at')->exists()) {
            $message = 'Venda ainda não lançada: lance a venda (com a entrada) antes de enviar.';
            $order->update(['send_error' => $message]);

            return $message;
        }
        try {
            $this->laboratory->send($order);

            return null;
        } catch (ValidationException $error) {
            $message = 'O laboratório recusou: '.collect($error->errors())->flatten()->unique()->implode(' ');
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
        $order->update(['send_error' => mb_substr($message, 0, 500)]);

        return $message;
    }

    /**
     * Campos do pedido como o formulário manda (salvar e editar).
     *
     * @return array<string, mixed>
     */
    private function fields(LabOrderRequest $request): array
    {
        return [
            'lab_connection_id' => $request->integer('lab_connection_id'),
            'client_id' => $request->integer('client_id'),
            'city_visit_id' => $request->validated('city_visit_id'),
            'sale_id' => $request->validated('sale_id'),
            'price' => $request->validated('price'),
            'payload' => collect($request->safe()->except(['lab_connection_id', 'client_id', 'service_place', 'city_visit_id', 'sale_id', 'price', 'prescription']))->all(),
            'prescription_path' => $request->file('prescription')?->store("companies/{$this->current->require()->id}/prescriptions", 'local'),
        ];
    }

    /**
     * A venda do óculos (se vier), conferida: aceita mudanças de óculos e valores e é do mesmo atendimento do pedido.
     *
     * @param  array<string, mixed>  $fields
     */
    private function saleFor(array $fields): ?Sale
    {
        if (! $fields['sale_id']) {
            return null;
        }
        $sale = Sale::with(SaleController::RELATIONS)->findOrFail($fields['sale_id']);
        SaleController::assertOpen($sale);
        if ($sale->city_visit_id !== ($fields['city_visit_id'] ? (int) $fields['city_visit_id'] : null)) {
            throw ValidationException::withMessages(['sale_id' => $sale->city_visit_id ? 'O óculos tem de ser do atendimento da venda.' : 'Venda da loja: o óculos é avulso (sem atendimento).']);
        }

        return $sale;
    }

    /**
     * Grava e, se a venda já foi lançada, ajusta o Financeiro junto (se ele recusar, desfaz).
     */
    private function withSale(?Sale $sale, callable $action): mixed
    {
        return $this->run(fn () => DB::transaction(function () use ($sale, $action) {
            $result = $action();
            if ($sale?->launched_at) {
                $this->financial->sync($sale->refresh()->load(SaleController::RELATIONS));
            }

            return $result;
        }));
    }

    /**
     * Pergunta ao laboratório a situação (quando o aviso de lá falhou).
     */
    public function refresh(LabOrder $labOrder): LabOrderResource
    {
        abort_unless($labOrder->sent_at, 422, 'O pedido ainda não foi enviado.');
        $this->run(fn () => $this->laboratory->refresh($labOrder));

        return $this->show($labOrder);
    }

    /**
     * Cancela enquanto o laboratório não separou as lentes (não enviado: só cancela aqui).
     */
    public function cancel(Request $request, LabOrder $labOrder): LabOrderResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:200']], ['reason.required' => 'Informe o motivo do cancelamento.'])['reason'];
        if (! $labOrder->cancelable() || $labOrder->status === 'canceled') {
            throw ValidationException::withMessages(['status' => "Pedido {$labOrder->status_label}: o laboratório já começou. Para cancelar, fale com o laboratório."]);
        }
        // Óculos de venda: pendente só enquanto a venda aceita mudanças; a venda (e o Financeiro) se ajusta junto.
        $sale = $labOrder->sale_id ? Sale::with(SaleController::RELATIONS)->find($labOrder->sale_id) : null;
        if ($sale && ! $labOrder->sent_at) {
            SaleController::assertOpen($sale);
        }
        $this->withSale($sale, function () use ($labOrder, $reason) {
            if (! $labOrder->sent_at) {
                $labOrder->update(['status' => 'canceled', 'status_label' => 'Cancelada', 'cancel_reason' => $reason, 'status_at' => now()]);
            } else {
                $this->laboratory->cancel($labOrder, $reason);
            }
        });

        return $this->show($labOrder);
    }

    private function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['connection' => $error->getMessage()]);
        }
    }
}
