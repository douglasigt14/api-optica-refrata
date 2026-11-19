<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Financial;
use App\Support\DashboardScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Parte financeira do Dashboard (19/11/2026): **só o admin** (rota com `role:admin`; para os outros o valor nem sai do
 * servidor). Mesmos filtros do dashboard de pedidos: contam as fichas não canceladas com algum óculos do filtro.
 * Mesmas contas do relatório das fichas: "a receber" = o que falta das lançadas; o valor das fichas em rascunho fica à parte.
 */
class FinancialDashboardController extends Controller
{
    public function __construct(private readonly Financial $financial) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scope = DashboardScope::fromRequest($request);
        $orders = $scope->orders()->get(['id', 'sale_id']);
        $sales = DashboardScope::salesOf($orders)->load('cityVisit.city');
        // Baixas feitas no Financeiro entram antes de somar (como no relatório; fora do ar, segue com o que tem).
        if ($this->financial->pull($sales)) {
            $sales->each->load('payments');
        }

        $methods = array_fill_keys(array_keys(SalePayment::METHODS), 0);
        $stages = ['down' => 0, 'delivery' => 0];
        foreach ($sales->flatMap->payments as $payment) {
            $methods[$payment->method] = ($methods[$payment->method] ?? 0) + $this->cents($payment->amount);
            $stages[$payment->stage === 'delivery' ? 'delivery' : 'down'] += $this->cents($payment->amount);
        }

        $byVisit = $sales->groupBy(fn (Sale $sale) => (string) $sale->city_visit_id)
            ->map(fn (Collection $group) => ['visit' => $this->visit($group->first()->cityVisit), ...$this->sums($group)])
            ->sortBy(fn (array $row) => $row['visit'] ? $row['visit']['visit_date'].'#'.str_pad((string) $row['visit']['id'], 10, '0', STR_PAD_LEFT) : '9999')
            ->values();

        return response()->json(['data' => [
            'period' => $scope->period(),
            'totals' => $this->sums($sales),
            'by_visit' => $byVisit,
            'payment_methods' => collect($methods)->map(fn (int $cents, string $method) => [
                'method' => $method,
                'label' => SalePayment::METHODS[$method] ?? $method,
                'amount' => $this->money($cents),
            ])->values(),
            'payment_stages' => collect($stages)->map(fn (int $cents, string $stage) => ['stage' => $stage, 'amount' => $this->money($cents)])->values(),
        ]]);
    }

    /**
     * Somas de um grupo de fichas: total (rascunho e lançadas), pago, a receber (das lançadas), rascunho e ticket médio.
     *
     * @param  Collection<int, Sale>  $sales
     * @return array<string, mixed>
     */
    private function sums(Collection $sales): array
    {
        $total = $sales->sum(fn (Sale $sale) => $sale->totalCents());

        return [
            'sales' => $sales->count(),
            'glasses' => $sales->sum(fn (Sale $sale) => $sale->activeOrders()->count()),
            'total' => $this->money($total),
            'paid' => $this->money($sales->sum(fn (Sale $sale) => $sale->paidCents())),
            'remaining' => $this->money($sales->filter(fn (Sale $sale) => $sale->status() === 'launched')->sum(fn (Sale $sale) => $sale->remainingCents())),
            'draft' => $this->money($sales->filter(fn (Sale $sale) => $sale->status() === 'draft')->sum(fn (Sale $sale) => $sale->totalCents())),
            'average_ticket' => $this->money($sales->isEmpty() ? 0 : (int) round($total / $sales->count())),
        ];
    }

    /** @return array<string, mixed>|null */
    private function visit(?CityVisit $visit): ?array
    {
        return $visit ? [
            'id' => $visit->id,
            'title' => $visit->title,
            'visit_date' => $visit->visit_date->toDateString(),
            'end_date' => $visit->end_date?->toDateString(),
            'city' => $visit->city?->only(['id', 'name', 'state']),
        ] : null;
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
