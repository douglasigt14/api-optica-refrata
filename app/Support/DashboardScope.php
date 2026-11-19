<?php

namespace App\Support;

use App\Models\CityVisit;
use App\Models\LabOrder;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Filtros do Dashboard (19/11/2026), iguais para a parte de pedidos e a financeira (só admin).
 *
 * - Sem atendimento escolhido: contam os óculos (pedidos ao laboratório) **feitos no período** (`created_at`, no fuso da
 *   empresa), opcionalmente só os de atendimentos de uma cidade.
 * - Com atendimento (`visit_id`): contam todos os óculos dele, sem olhar o período.
 *
 * Óculos cancelados nunca contam. Os que ainda não foram ao laboratório contam (a ficha já existe).
 */
final class DashboardScope
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $cityId,
        public readonly ?CityVisit $visit,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'city_id' => ['nullable', 'integer'],
            'visit_id' => ['nullable', 'integer'],
        ], [
            'to.after_or_equal' => 'A data final não pode ser antes da inicial.',
        ]);

        $timezone = config('app.business_timezone');
        $today = Carbon::now($timezone);
        $visit = isset($filters['visit_id']) ? CityVisit::query()->findOrFail($filters['visit_id']) : null;

        return new self(
            from: isset($filters['from']) ? Carbon::parse($filters['from'], $timezone) : $today->copy()->startOfMonth(),
            to: isset($filters['to']) ? Carbon::parse($filters['to'], $timezone) : $today->copy()->endOfMonth(),
            cityId: $visit?->city_id ?? $filters['city_id'] ?? null,
            visit: $visit,
        );
    }

    /** Limites do período em UTC, que é como o `created_at` está gravado. @return array{0: Carbon, 1: Carbon} */
    public function createdBetween(): array
    {
        return [$this->from->copy()->startOfDay()->utc(), $this->to->copy()->endOfDay()->utc()];
    }

    /** O período mostrado: o escolhido, ou os dias do atendimento. @return array{from: string, to: string} */
    public function period(): array
    {
        if ($this->visit) {
            return ['from' => $this->visit->visit_date->toDateString(), 'to' => ($this->visit->end_date ?? $this->visit->visit_date)->toDateString()];
        }

        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    /**
     * Óculos que contam.
     *
     * @return Builder<LabOrder>
     */
    public function orders(): Builder
    {
        return LabOrder::query()
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', 'canceled'))
            ->when(
                $this->visit,
                fn (Builder $q) => $q->where('city_visit_id', $this->visit->id),
                fn (Builder $q) => $q
                    ->whereBetween('created_at', $this->createdBetween())
                    ->when($this->cityId, fn (Builder $q) => $q->whereHas('cityVisit', fn (Builder $v) => $v->where('city_id', $this->cityId))),
            );
    }

    /**
     * Atendimentos do filtro: o escolhido, ou os que encostam no período (como no calendário).
     *
     * @return Builder<CityVisit>
     */
    public function visits(): Builder
    {
        if ($this->visit) {
            return CityVisit::query()->whereKey($this->visit->id);
        }

        return CityVisit::query()
            ->whereRaw('COALESCE(end_date, visit_date) >= ?', [$this->from->toDateString()])
            ->where('visit_date', '<=', $this->to->toDateString())
            ->when($this->cityId, fn ($q) => $q->where('city_id', $this->cityId));
    }

    /**
     * Fichas (vendas) não canceladas com algum óculos que conta, com os óculos e pagamentos carregados.
     *
     * @param  Collection<int, LabOrder>  $orders
     * @return Collection<int, Sale>
     */
    public static function salesOf(Collection $orders): Collection
    {
        $ids = $orders->pluck('sale_id')->filter()->unique()->values();

        return $ids->isEmpty()
            ? collect()
            : Sale::query()->whereIn('id', $ids)->whereNull('canceled_at')->with(['orders', 'payments'])->get();
    }
}
