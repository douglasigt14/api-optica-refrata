<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\LabOrder;
use App\Models\Sale;
use App\Support\PhonePrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificação de entregas (21/11/2026): os clientes das fichas do atendimento (quem paga e retira), para o operador
 * avisar pelo WhatsApp que os óculos chegaram, anotar o parecer (o que a pessoa disse) e marcar quem já foi avisado.
 * Entram as fichas lançadas e as entregues; rascunho e cancelada, não.
 */
class DeliveryNoticeController extends Controller
{
    public function index(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $sales = Sale::query()->where('city_visit_id', $cityVisit->id)->whereNotNull('launched_at')->whereNull('canceled_at')
            ->with(['client', 'orders', 'deliveryNotifiedBy:id,name'])->orderBy('id')->get();

        return response()->json(['data' => $sales->map(fn (Sale $sale) => $this->row($sale, $request))->values()]);
    }

    /** Marca (ou desmarca) "avisado" e guarda o parecer. */
    public function update(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate([
            'notified' => ['required', 'boolean'],
            'notice' => ['nullable', 'string', 'max:2000'],
        ]);
        $notified = (bool) $data['notified'];
        $sale->forceFill([
            'delivery_notice' => filled($data['notice'] ?? null) ? trim($data['notice']) : null,
            // Já avisado continua com a data e quem avisou da primeira vez; desmarcar limpa.
            'delivery_notified_at' => $notified ? ($sale->delivery_notified_at ?? now()) : null,
            'delivery_notified_by' => $notified ? ($sale->delivery_notified_by ?? $request->user()->id) : null,
        ])->saveQuietly();

        return response()->json(['data' => $this->row($sale->load(['client', 'orders', 'deliveryNotifiedBy:id,name']), $request)]);
    }

    /** @return array<string, mixed> */
    private function row(Sale $sale, Request $request): array
    {
        $orders = $sale->activeOrders();

        return [
            'id' => $sale->id,
            'status' => $sale->status(),
            'client' => $sale->client ? [
                'id' => $sale->client->id,
                'name' => $sale->client->name,
                ...PhonePrivacy::fields($sale->client->phone_number, $request),
            ] : null,
            'glasses' => $orders->count(),
            // Óculos que o laboratório já deu como prontos (ou já mandou para a ótica).
            'glasses_ready' => $orders->filter(fn (LabOrder $order) => in_array($order->status, ['ready', 'delivered'], true))->count(),
            'notified' => $sale->delivery_notified_at !== null,
            'notified_at' => $sale->delivery_notified_at?->toIso8601String(),
            'notified_by' => $sale->deliveryNotifiedBy?->name,
            'notice' => $sale->delivery_notice,
            'delivered_at' => $sale->delivered_at?->toIso8601String(),
        ];
    }
}
