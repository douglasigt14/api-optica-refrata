<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\CityVisitStatus;
use App\Models\CityVisit;
use App\Models\LabOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aviso do laboratório (passo 3 do roteiro; chave gravada pelo Console): a situação nova de uma OS que veio de um pedido
 * desta ótica. `external_id` = o nº do pedido aqui.
 */
class LabIntegrationController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:40'],
            'number' => ['nullable', 'string', 'max:20'],
            'status' => ['required', 'string', 'max:30'],
            'status_label' => ['required', 'string', 'max:60'],
            'awaits_frame' => ['sometimes', 'boolean'],
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);
        $order = LabOrder::where('lab_connection_id', $request->attributes->get('lab')->id)->whereKey((int) $data['external_id'])->firstOrFail();
        $order->applyLabStatus($data);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Atendimentos desta ótica (menos os cancelados), do mais novo ao mais antigo: o laboratório liga o lote a um deles.
     */
    public function cityVisits(): JsonResponse
    {
        $visits = CityVisit::with('city')
            ->where('status', '!=', CityVisitStatus::Cancelled)
            ->orderByDesc('visit_date')->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $visits->map(fn (CityVisit $visit) => [
            'id' => $visit->id,
            'title' => $visit->title,
            'city' => $visit->city?->name,
            'state' => $visit->city?->state,
            'visit_date' => $visit->visit_date?->toDateString(),
            'end_date' => $visit->end_date?->toDateString(),
            'status' => $visit->status,
        ])]);
    }
}
