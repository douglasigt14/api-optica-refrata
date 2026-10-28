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
 * Pedidos aos laboratórios do grupo (admin e atendente; passo 3 do roteiro). O pedido vira OS no laboratório; a situação
 * volta pelo aviso de lá (ou "Atualizar"). Erro de preenchimento: volta ao formulário e nada fica salvo. Sem conexão: o
 * pedido fica "Não enviado", com o erro e o "Enviar de novo". Cancelar: enquanto o laboratório não separou as lentes.
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
            ->with(['lab', 'client', 'user'])
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['lab_connection_id'] ?? null, fn ($q, int $id) => $q->where('lab_connection_id', $id))
            ->when($filters['client_id'] ?? null, fn ($q, int $id) => $q->where('client_id', $id))
            ->when($request->boolean('not_sent'), fn ($q) => $q->whereNull('sent_at'))
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
        return new LabOrderResource($labOrder->load(['lab', 'client', 'user']));
    }

    public function store(LabOrderRequest $request): JsonResponse
    {
        $order = LabOrder::create([
            'lab_connection_id' => $request->integer('lab_connection_id'),
            'client_id' => $request->integer('client_id'),
            'user_id' => $request->user()->id,
            'payload' => collect($request->safe()->except(['lab_connection_id', 'client_id', 'prescription']))->all(),
            'prescription_path' => $request->file('prescription')?->store("companies/{$this->current->require()->id}/prescriptions", 'local'),
        ]);

        try {
            $this->laboratory->send($order);
        } catch (ValidationException $error) {
            // Preenchimento recusado pelo laboratório: volta ao formulário, nada fica.
            if ($order->prescription_path) {
                Storage::disk('local')->delete($order->prescription_path);
            }
            $order->delete();
            throw $error;
        } catch (RuntimeException $error) {
            $order->update(['send_error' => mb_substr($error->getMessage(), 0, 500)]);
        }

        return (new LabOrderResource($order->fresh(['lab', 'client', 'user'])))->response()->setStatusCode(201);
    }

    /**
     * Manda de novo o pedido que não foi enviado.
     */
    public function send(LabOrder $labOrder): LabOrderResource
    {
        if ($labOrder->sent_at) {
            throw ValidationException::withMessages(['status' => 'Este pedido já foi enviado ao laboratório.']);
        }
        try {
            $this->laboratory->send($labOrder);
        } catch (RuntimeException $error) {
            $labOrder->update(['send_error' => mb_substr($error->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['connection' => $error->getMessage()]);
        }

        return $this->show($labOrder);
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
