<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LabOrderRequest;
use App\Http\Resources\Admin\LabOrderResource;
use App\Models\LabConnection;
use App\Models\LabOrder;
use App\Services\Laboratory;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pedidos aos laboratórios do grupo (admin e atendente; passo 3 do roteiro). Desde 04/11/2026 o pedido é **salvo** e fica
 * "Pendente de envio": confere, edita e só depois envia (um ou vários marcados). Lote a ótica não abre (só o
 * laboratório): com atendimento, a OS entra no lote aberto dele lá, se houver. Enviado, vira OS lá e a situação volta pelo aviso (ou "Atualizar"). Recusa ou falha no envio: o pedido
 * continua pendente, com o erro em `send_error`. Cancelar: enquanto o laboratório não separou as lentes.
 */
class LabOrderController extends Controller
{
    public function __construct(private Laboratory $laboratory, private CurrentCompany $current) {}

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
        ]);

        $orders = LabOrder::query()
            ->with(['lab', 'client', 'user', 'cityVisit.city'])
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['lab_connection_id'] ?? null, fn ($q, int $id) => $q->where('lab_connection_id', $id))
            ->when($filters['client_id'] ?? null, fn ($q, int $id) => $q->where('client_id', $id))
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
        $order = LabOrder::create([...$this->fields($request), 'user_id' => $request->user()->id]);

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
        if ($fields['prescription_path'] === null) {
            unset($fields['prescription_path']);
        } elseif ($labOrder->prescription_path) {
            Storage::disk('local')->delete($labOrder->prescription_path);
        }
        $labOrder->update([...$fields, 'send_error' => null]);

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
        $error = $this->trySend($labOrder);
        if ($error !== null) {
            throw ValidationException::withMessages(['connection' => $error]);
        }

        return $this->show($labOrder);
    }

    /**
     * Envia os pendentes marcados, cada um sozinho (no laboratório, a OS com atendimento entra no lote aberto dele, se
     * houver; senão vai avulsa). Lote a ótica não abre (04/11/2026). Devolve o resultado de cada um.
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

        $results = $orders->map(fn (LabOrder $order) => ['id' => $order->id, 'error' => $this->trySend($order)]);

        return response()->json([
            'data' => [
                'sent' => $results->whereNull('error')->count(),
                'results' => $results->values()->all(),
            ],
        ]);
    }

    /**
     * Manda um pedido; null = foi. Falhou: grava o erro no pedido (continua pendente) e devolve a mensagem.
     */
    private function trySend(LabOrder $order): ?string
    {
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
            'payload' => collect($request->safe()->except(['lab_connection_id', 'client_id', 'service_place', 'city_visit_id', 'prescription']))->all(),
            'prescription_path' => $request->file('prescription')?->store("companies/{$this->current->require()->id}/prescriptions", 'local'),
        ];
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
        if (! $labOrder->sent_at) {
            $labOrder->update(['status' => 'canceled', 'status_label' => 'Cancelada', 'cancel_reason' => $reason, 'status_at' => now()]);
        } else {
            $this->run(fn () => $this->laboratory->cancel($labOrder, $reason));
        }

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
