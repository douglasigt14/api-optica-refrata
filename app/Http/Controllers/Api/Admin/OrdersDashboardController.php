<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CityVisitStatus;
use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\LabOrder;
use App\Support\DashboardScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Dashboard de pedidos (19/11/2026; admin e atendente): óculos, fichas e clientes do período ou de um atendimento, sem
 * nenhum valor em R$ (os valores ficam em `FinancialDashboardController`, só do admin). Filtros em `DashboardScope`.
 */
class OrdersDashboardController extends Controller
{
    private const TOP = 10;

    /** Faixas de idade do paciente: [rótulo, até a idade]. */
    private const AGE_RANGES = [['0-12', 12], ['13-17', 17], ['18-29', 29], ['30-39', 39], ['40-49', 49], ['50-59', 59], ['60+', PHP_INT_MAX]];

    public function __invoke(Request $request): JsonResponse
    {
        $scope = DashboardScope::fromRequest($request);
        $timezone = config('app.business_timezone');

        $orders = $scope->orders()
            ->with(['client', 'cityVisit.city', 'user:id,name', 'sale:id,client_id,user_id,delivered_at,canceled_at', 'sale.client'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $visits = $scope->visits()->with('city')->get();
        $visitIds = $orders->pluck('city_visit_id')->filter()->unique()->diff($visits->pluck('id'));
        if ($visitIds->isNotEmpty()) {
            // Óculos do período de um atendimento que não encosta nele (ex.: pedido feito depois): o atendimento entra também.
            $visits = $visits->concat(CityVisit::query()->with('city')->whereIn('id', $visitIds)->get());
        }

        $sold = fn (Collection $group) => $group->filter(fn (LabOrder $order) => $order->sale && ! $order->sale->canceled_at)->pluck('sale_id')->unique()->count();
        $buckets = fn (Collection $group) => array_merge(
            ['not_sent' => 0, 'in_lab' => 0, 'ready' => 0, 'delivered' => 0],
            $group->countBy(fn (LabOrder $order) => $this->bucket($order))->all(),
        );

        $byStatus = $visits->countBy(fn (CityVisit $visit) => $visit->status->value);

        return response()->json(['data' => [
            'period' => $scope->period(),
            'visit' => $scope->visit ? $this->visit($visits->firstWhere('id', $scope->visit->id)) : null,
            'totals' => [
                'glasses' => $orders->count(),
                'sales' => $sold($orders),
                'clients' => $orders->pluck('client_id')->unique()->count(),
                ...$buckets($orders),
                'visits' => $visits->count(),
                'visits_by_status' => collect(CityVisitStatus::cases())->mapWithKeys(fn (CityVisitStatus $status) => [$status->value => (int) ($byStatus[$status->value] ?? 0)]),
            ],
            // Um por atendimento (os do período, mesmo sem óculos), do mais antigo ao mais novo; a loja (sem atendimento) no fim.
            'by_visit' => $visits
                ->sortBy([fn (CityVisit $a, CityVisit $b) => $a->visit_date <=> $b->visit_date, fn (CityVisit $a, CityVisit $b) => $a->id <=> $b->id])
                ->map(fn (CityVisit $visit) => $this->visitRow($visit, $orders->where('city_visit_id', $visit->id), $sold, $buckets))
                ->values()
                ->when(
                    $orders->whereNull('city_visit_id')->isNotEmpty(),
                    fn (Collection $rows) => $rows->push($this->visitRow(null, $orders->whereNull('city_visit_id'), $sold, $buckets)),
                )
                ->values(),
            'lens_types' => $this->count($orders->map(fn (LabOrder $order) => $order->payload['lens_type'] ?? null)),
            'frame_types' => $this->count($orders->map(fn (LabOrder $order) => $order->payload['frame_type'] ?? null)),
            'prescription_types' => $this->prescriptionTypes($orders),
            'referral_sources' => $this->count($this->people($orders)->map(fn (Client $client) => $client->referral_source)),
            'age_ranges' => $this->ageRanges($orders->pluck('client')->filter()->unique('id')),
            // Segunda a domingo (1 = segunda), pelo dia em que o óculos foi lançado.
            'weekdays' => collect(range(1, 7))
                ->map(fn (int $day) => ['weekday' => $day, 'total' => $orders->filter(fn (LabOrder $order) => $order->created_at->copy()->setTimezone($timezone)->dayOfWeekIso === $day)->count()])
                ->values(),
            'ranking_users' => $orders
                ->groupBy(fn (LabOrder $order) => (string) $order->user_id)
                ->map(fn (Collection $group) => [
                    'user' => $group->first()->user ? ['id' => $group->first()->user->id, 'name' => $group->first()->user->name] : null,
                    'glasses' => $group->count(),
                    'sales' => $sold($group),
                ])
                ->sortByDesc('glasses')
                ->take(self::TOP)
                ->values(),
            'ranking_cities' => $orders
                ->filter(fn (LabOrder $order) => $order->cityVisit?->city)
                ->groupBy(fn (LabOrder $order) => $order->cityVisit->city_id)
                ->map(fn (Collection $group) => [
                    'city' => $group->first()->cityVisit->city->only(['id', 'name', 'state']),
                    'glasses' => $group->count(),
                    'clients' => $group->pluck('client_id')->unique()->count(),
                    'visits' => $group->pluck('city_visit_id')->unique()->count(),
                ])
                ->sortByDesc('glasses')
                ->take(self::TOP)
                ->values(),
            // O nome do tratamento vem do catálogo do laboratório (a tela busca pelo `lab_connection_id`).
            'treatments' => $orders
                ->flatMap(fn (LabOrder $order) => collect($order->payload['treatment_ids'] ?? [])->unique()->map(fn ($id) => $order->lab_connection_id.':'.(int) $id))
                ->countBy()
                ->sortDesc()
                ->take(self::TOP)
                ->map(function (int $total, string $key) {
                    [$lab, $id] = explode(':', $key);

                    return ['lab_connection_id' => (int) $lab, 'treatment_id' => (int) $id, 'total' => $total];
                })
                ->values(),
            'frame_colors' => $this->top($orders->map(fn (LabOrder $order) => $order->payload['frame_color'] ?? null)),
            'frame_brands' => $this->top($orders->map(fn (LabOrder $order) => $order->payload['frame_brand'] ?? null)),
            'latest_orders' => $orders->take(self::TOP)->map(fn (LabOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'client' => $order->client ? ['id' => $order->client->id, 'name' => $order->client->name] : null,
                'visit' => $order->cityVisit ? ['id' => $order->cityVisit->id, 'title' => $order->cityVisit->title, 'city' => $order->cityVisit->city?->name] : null,
                'lens_type' => $order->payload['lens_type'] ?? null,
                'status' => $order->status,
                'status_label' => $order->status_label,
                'pending' => $order->sent_at === null,
                'stage' => $this->bucket($order),
                'created_at' => $order->created_at,
            ])->values(),
        ]]);
    }

    /**
     * Onde o óculos está: `not_sent` (ainda não foi ao laboratório), `in_lab`, `ready` (pronto ou já na ótica) e
     * `delivered` (a ficha foi entregue ao cliente).
     */
    private function bucket(LabOrder $order): string
    {
        return match (true) {
            $order->sale?->delivered_at !== null => 'delivered',
            $order->sent_at === null => 'not_sent',
            in_array($order->status, ['ready', 'delivered'], true) => 'ready',
            default => 'in_lab',
        };
    }

    /** @return array<string, mixed> */
    private function visit(?CityVisit $visit): ?array
    {
        return $visit ? [
            'id' => $visit->id,
            'title' => $visit->title,
            'visit_date' => $visit->visit_date->toDateString(),
            'end_date' => $visit->end_date?->toDateString(),
            'status' => $visit->status->value,
            'city' => $visit->city?->only(['id', 'name', 'state']),
        ] : null;
    }

    /** @return array<string, mixed> */
    private function visitRow(?CityVisit $visit, Collection $orders, callable $sold, callable $buckets): array
    {
        return [
            'visit' => $this->visit($visit),
            'glasses' => $orders->count(),
            'sales' => $sold($orders),
            'clients' => $orders->pluck('client_id')->unique()->count(),
            ...$buckets($orders),
        ];
    }

    /**
     * Quantos de cada valor (vazio = `null`), do mais comum ao menos.
     *
     * @return list<array{value: ?string, total: int}>
     */
    private function count(Collection $values): array
    {
        return $values
            ->map(fn ($value) => $value === null || $value === '' ? '' : (string) $value)
            ->countBy()
            ->sortDesc()
            ->map(fn (int $total, string $value) => ['value' => $value === '' ? null : $value, 'total' => $total])
            ->values()
            ->all();
    }

    /**
     * Os mais usados de um texto livre (cor, marca), juntando maiúsculas e espaços; os vazios ficam de fora.
     *
     * @return list<array{value: string, total: int}>
     */
    private function top(Collection $values): array
    {
        return $values
            ->map(fn ($value) => trim(preg_replace('/\s+/', ' ', (string) $value)))
            ->filter()
            ->map(fn (string $value) => mb_convert_case(mb_strtolower($value), MB_CASE_TITLE))
            ->countBy()
            ->sortDesc()
            ->take(self::TOP)
            ->map(fn (int $total, string $value) => ['value' => $value, 'total' => $total])
            ->values()
            ->all();
    }

    /**
     * Pessoas que compraram, uma vez cada: quem paga a ficha (ou o paciente, no óculos sem ficha).
     *
     * @return Collection<int, Client>
     */
    private function people(Collection $orders): Collection
    {
        return $orders->map(fn (LabOrder $order) => $order->sale?->client ?? $order->client)->filter()->unique('id')->values();
    }

    /**
     * Pacientes por faixa de idade (hoje), na ordem das faixas; sem data nem idade = `null`.
     *
     * @return list<array{value: ?string, total: int}>
     */
    private function ageRanges(Collection $clients): array
    {
        $counts = $clients->countBy(function (Client $client) {
            $age = $client->currentAge();
            if ($age === null) {
                return '';
            }
            foreach (self::AGE_RANGES as [$label, $max]) {
                if ($age <= $max) {
                    return $label;
                }
            }

            return '';
        });

        return collect(self::AGE_RANGES)
            ->map(fn (array $range) => ['value' => $range[0], 'total' => (int) ($counts[$range[0]] ?? 0)])
            ->push(['value' => null, 'total' => (int) ($counts[''] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * Óculos com cada tipo de grau na receita (um óculos pode ter mais de um): miopia (esférico negativo), hipermetropia
     * (esférico positivo), astigmatismo (cilíndrico) e presbiopia (adição).
     *
     * @return array<string, int>
     */
    private function prescriptionTypes(Collection $orders): array
    {
        $types = ['myopia' => 0, 'hyperopia' => 0, 'astigmatism' => 0, 'presbyopia' => 0];
        foreach ($orders as $order) {
            $eyes = collect($order->payload['eyes'] ?? []);
            $has = [
                'myopia' => $eyes->contains(fn ($eye) => (float) ($eye['sphere'] ?? 0) < 0),
                'hyperopia' => $eyes->contains(fn ($eye) => (float) ($eye['sphere'] ?? 0) > 0),
                'astigmatism' => $eyes->contains(fn ($eye) => (float) ($eye['cylinder'] ?? 0) != 0),
                'presbyopia' => $eyes->contains(fn ($eye) => (float) ($eye['addition'] ?? 0) > 0),
            ];
            foreach ($has as $type => $yes) {
                $types[$type] += $yes ? 1 : 0;
            }
        }

        return $types;
    }
}
