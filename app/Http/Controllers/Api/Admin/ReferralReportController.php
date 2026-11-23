<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\LabOrder;
use Illuminate\Http\JsonResponse;

/**
 * Relatório "Quem indicou e como conheceu" do atendimento (23/11/2026): os óculos **vendidos** (de ficha lançada ou
 * entregue; sem rascunho, ficha cancelada nem óculos cancelado) e, do **cliente da ficha** (quem paga), o "Quem indicou"
 * (`referred_by`) e o "Por que você veio?" (`referral_source`). Resumo por como conheceu e por quem indicou.
 */
class ReferralReportController extends Controller
{
    public function visit(CityVisit $cityVisit): JsonResponse
    {
        $cityVisit->load('city');
        $orders = LabOrder::query()
            ->where('city_visit_id', $cityVisit->id)
            ->whereHas('sale', fn ($query) => $query->whereNotNull('launched_at')->whereNull('canceled_at'))
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'canceled'))
            ->with(['client', 'sale.client'])
            ->orderBy('number')
            ->get();

        $rows = $orders->map(function (LabOrder $order) {
            $buyer = $order->sale->client;
            $referredBy = trim((string) $buyer?->referred_by);

            return [
                'id' => $order->id,
                'number' => $order->number,
                'sale_id' => $order->sale_id,
                'buyer' => $buyer ? ['id' => $buyer->id, 'name' => $buyer->name] : null,
                'patient' => $order->client?->name,
                'referred_by' => $referredBy !== '' ? $referredBy : null,
                'referral_source' => $buyer?->referral_source,
            ];
        })->values();

        // Como conheceu: as opções do cadastro, na ordem, e quantos óculos sem resposta.
        $sources = collect([...Client::REFERRAL_SOURCES, null])->map(fn (?string $source) => [
            'source' => $source,
            'glasses' => $rows->where('referral_source', $source)->count(),
            'clients' => $rows->where('referral_source', $source)->pluck('buyer.id')->unique()->count(),
        ])->values();

        // Quem indicou: o mesmo nome (sem diferença de maiúsculas e espaços) junta; mais óculos primeiro.
        $referrers = $rows->whereNotNull('referred_by')
            ->groupBy(fn (array $row) => mb_strtolower(preg_replace('/\s+/', ' ', $row['referred_by'])))
            ->map(fn ($group) => [
                'name' => $group->first()['referred_by'],
                'glasses' => $group->count(),
                'clients' => $group->pluck('buyer.name')->unique()->values(),
            ])
            ->sortBy([['glasses', 'desc'], ['name', 'asc']])
            ->values();

        return response()->json(['data' => [
            'visit' => [
                'id' => $cityVisit->id,
                'title' => $cityVisit->title,
                'city' => $cityVisit->city?->name,
                'visit_date' => $cityVisit->visit_date?->toDateString(),
                'end_date' => $cityVisit->end_date?->toDateString(),
            ],
            'glasses' => $rows,
            'clients' => $rows->pluck('buyer.id')->unique()->count(),
            'without_referrer' => $rows->whereNull('referred_by')->count(),
            'sources' => $sources,
            'referrers' => $referrers,
        ]]);
    }
}
