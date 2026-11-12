<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CityVisitStatus;
use App\Enums\CrmChannel;
use App\Enums\CrmResult;
use App\Enums\CrmStage;
use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\CrmActivity;
use App\Models\CrmEntry;
use App\Models\Sale;
use App\Support\PhonePrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRM da Ótica, fase 2 (12/11/2026; admin e atendente): o call center chama de volta os **clientes** (quem já comprou)
 * para cada atendimento na cidade. Por atendimento: os números do topo (como a planilha), a lista/quadro dos clientes da
 * cidade com a etapa do CRM, registrar contato (o resultado puxa a etapa), mover a etapa à mão, "Não foi" em lote,
 * resultados e ranking por atendente, e os retornos do dia. Não usa as indicações (ver "Indicação × Cliente").
 */
class CrmController extends Controller
{
    /** Faixas pela última compra, como na planilha. */
    private const PERIODS = ['ate_6m' => 'Até 6 meses', '6m_1a' => '6 meses a 1 ano', '1a_2a' => 'Mais de 1 ano', 'mais_2a' => 'Mais de 2 anos', 'sem_data' => 'Sem data'];

    /**
     * Atendimentos para o CRM (não cancelados): os próximos e em andamento, do mais perto ao mais longe; com `ended=1`, os
     * que já passaram (do mais recente). Cada um com quantos clientes há na cidade, quantos já foram contatados e quantos
     * marcaram.
     */
    public function visits(Request $request): JsonResponse
    {
        $ended = $request->boolean('ended');
        $today = today()->toDateString();
        $visits = CityVisit::query()->with('city')
            ->where('status', '!=', CityVisitStatus::Cancelled)
            ->when($ended,
                fn ($q) => $q->whereRaw('COALESCE(end_date, visit_date) < ?', [$today])->orderByDesc('visit_date')->limit(60),
                fn ($q) => $q->whereRaw('COALESCE(end_date, visit_date) >= ?', [$today])->orderBy('visit_date'))
            ->orderBy('id')->get();

        $clients = Client::query()->whereIn('city_id', $visits->pluck('city_id')->unique())->selectRaw('city_id, COUNT(*) as total')->groupBy('city_id')->pluck('total', 'city_id');
        $booked = array_map(fn (CrmStage $s) => $s->value, array_filter(CrmStage::cases(), fn (CrmStage $s) => $s->hasBooked()));
        $entries = CrmEntry::query()->whereIn('city_visit_id', $visits->pluck('id'))
            ->selectRaw('city_visit_id, SUM(CASE WHEN last_contact_at IS NOT NULL THEN 1 ELSE 0 END) as contacted')
            ->selectRaw('SUM(CASE WHEN stage IN ('.implode(',', array_fill(0, count($booked), '?')).') THEN 1 ELSE 0 END) as booked', $booked)
            ->groupBy('city_visit_id')->get()->keyBy('city_visit_id');

        return response()->json(['data' => $visits->map(fn (CityVisit $visit) => [
            ...self::visitData($visit),
            'clients_count' => (int) ($clients[$visit->city_id] ?? 0),
            'contacted_count' => (int) ($entries[$visit->id]->contacted ?? 0),
            'booked_count' => (int) ($entries[$visit->id]->booked ?? 0),
        ])->values()]);
    }

    /**
     * Os números do atendimento: cards do topo, resultados e ranking por atendente.
     */
    public function summary(CityVisit $cityVisit): JsonResponse
    {
        $cityVisit->load('city');
        [, $y1] = self::thresholds();
        $clients = Client::query()->where('city_id', $cityVisit->city_id);
        $total = (clone $clients)->count();
        $overYear = (clone $clients)->whereNotNull('last_purchase_date')->where('last_purchase_date', '<', $y1)->count();
        $underYear = (clone $clients)->where('last_purchase_date', '>=', $y1)->count();

        $entries = CrmEntry::query()->where('city_visit_id', $cityVisit->id)->get(['id', 'client_id', 'stage', 'last_contact_at']);
        $byStage = $entries->countBy(fn (CrmEntry $e) => $e->stage->value);
        $contacted = $entries->whereNotNull('last_contact_at')->count();
        $booked = $entries->filter(fn (CrmEntry $e) => $e->stage->hasBooked())->count();
        $went = (int) ($byStage[CrmStage::Attended->value] ?? 0) + (int) ($byStage[CrmStage::Bought->value] ?? 0);
        $noShow = (int) ($byStage[CrmStage::NoShow->value] ?? 0);
        $bought = (int) ($byStage[CrmStage::Bought->value] ?? 0);
        $buyers = $entries->filter(fn (CrmEntry $e) => $e->stage === CrmStage::Bought)->pluck('client_id');
        $revenueCents = Sale::query()->with('orders')->where('city_visit_id', $cityVisit->id)->whereIn('client_id', $buyers)
            ->whereNotNull('launched_at')->whereNull('canceled_at')->get()->sum(fn (Sale $sale) => $sale->totalCents());
        $rescheduled = CrmActivity::query()->where('city_visit_id', $cityVisit->id)->where('kind', 'contact')->where('result', CrmResult::Booked)
            ->selectRaw('client_id')->groupBy('client_id')->havingRaw('COUNT(*) > 1')->get()->count();

        $ranking = CrmActivity::query()->with('user:id,name')->where('city_visit_id', $cityVisit->id)->where('kind', 'contact')->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as contacts, SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as bookings', [CrmResult::Booked->value])
            ->groupBy('user_id')->orderByDesc('bookings')->orderByDesc('contacts')->get()
            ->map(fn (CrmActivity $row) => ['user' => $row->user?->only(['id', 'name']), 'contacts' => (int) $row->contacts, 'bookings' => (int) $row->bookings])->values();

        return response()->json(['data' => [
            'visit' => self::visitData($cityVisit),
            'cards' => [
                'clients' => $total,
                'over_one_year' => $overYear,
                'under_one_year' => $underYear,
                'contacted' => $contacted,
                'booked' => $booked,
                'to_contact' => max(0, $total - $contacted),
                'went' => $went,
                'bought' => $bought,
                'revenue' => round($revenueCents / 100, 2),
            ],
            'stages' => collect(CrmStage::cases())->map(fn (CrmStage $s) => [
                'stage' => $s->value, 'label' => $s->label(),
                'count' => $s === CrmStage::ToContact ? $total - $entries->where('stage', '!=', CrmStage::ToContact)->count() : (int) ($byStage[$s->value] ?? 0),
            ])->values(),
            'results' => [
                'booked' => $booked,
                'went' => $went,
                'no_show' => $noShow,
                'rescheduled' => $rescheduled,
                'bought' => $bought,
                'revenue' => round($revenueCents / 100, 2),
                // % que foi: dos que marcaram e já têm desfecho (foi ou não foi). Conversão: dos que foram, quantos compraram.
                'attendance_rate' => $went + $noShow > 0 ? round($went / ($went + $noShow), 4) : null,
                'conversion_rate' => $went > 0 ? round($bought / $went, 4) : null,
            ],
            'ranking' => $ranking,
        ]]);
    }

    /**
     * Clientes da cidade do atendimento, com a etapa do CRM. Filtros: `stage`, `period` (faixa da última compra),
     * `user_id` (último contato de quem), `follow_up=due` (retorno para hoje ou atrasado), `search` (nome), `order`
     * (`name`, `last_purchase`, `last_contact`).
     */
    public function clients(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::enum(CrmStage::class)],
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'user_id' => ['nullable', 'integer'],
            'follow_up' => ['nullable', Rule::in(['due'])],
            'search' => ['nullable', 'string', 'max:100'],
            'order' => ['nullable', Rule::in(['name', 'last_purchase', 'last_contact'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Client::query()->where('clients.city_id', $cityVisit->city_id)
            ->leftJoin('crm_entries as e', fn ($j) => $j->on('e.client_id', '=', 'clients.id')->where('e.city_visit_id', '=', $cityVisit->id))
            ->select('clients.*', 'e.id as entry_id');
        if ($stage = $filters['stage'] ?? null) {
            $stage === CrmStage::ToContact->value
                ? $query->where(fn ($q) => $q->whereNull('e.id')->orWhere('e.stage', $stage))
                : $query->where('e.stage', $stage);
        }
        if ($period = $filters['period'] ?? null) {
            self::wherePeriod($query, $period);
        }
        $query->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('e.last_user_id', $id))
            ->when(($filters['follow_up'] ?? null) === 'due', fn ($q) => $q->whereNotNull('e.follow_up_at')->whereDate('e.follow_up_at', '<=', today()->toDateString()))
            ->when(trim($filters['search'] ?? ''), fn ($q, $search) => $q->where('clients.name', 'like', "%{$search}%"));
        match ($filters['order'] ?? 'name') {
            'last_purchase' => $query->orderByRaw('clients.last_purchase_date IS NULL')->orderBy('clients.last_purchase_date'),
            'last_contact' => $query->orderByRaw('e.last_contact_at IS NULL')->orderByDesc('e.last_contact_at'),
            default => null,
        };
        $page = $query->orderBy('clients.name')->orderBy('clients.id')->paginate($filters['per_page'] ?? 25);

        $entries = CrmEntry::query()->with('lastUser:id,name')->whereIn('id', collect($page->items())->pluck('entry_id')->filter())->get()->keyBy('id');
        $data = collect($page->items())->map(fn (Client $client) => self::clientRow($client, $client->entry_id ? $entries[$client->entry_id] ?? null : null));

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    /**
     * Um cliente no atendimento: dados (sem o telefone, salvo para o admin), a etapa e a linha do tempo de todos os
     * atendimentos (do mais recente).
     */
    public function client(CityVisit $cityVisit, Client $client): JsonResponse
    {
        $entry = CrmEntry::query()->with('lastUser:id,name')->where('city_visit_id', $cityVisit->id)->where('client_id', $client->id)->first();
        $timeline = CrmActivity::query()->with(['user:id,name', 'cityVisit.city'])->where('client_id', $client->id)->latest()->latest('id')->limit(200)->get();

        return response()->json(['data' => [
            ...self::clientRow($client->load('city'), $entry),
            'city' => $client->city?->only(['id', 'name', 'state']),
            'address' => $client->address,
            'notes' => $client->notes,
            'visit' => self::visitData($cityVisit->load('city')),
            'timeline' => $timeline->map(fn (CrmActivity $a) => self::activityData($a))->values(),
        ]]);
    }

    /**
     * Registra um contato: canal, resultado, observação e, conforme o resultado, o dia marcado ("Marcou") ou a data do
     * retorno ("Retornar depois"). O resultado puxa a etapa (quem já foi ao atendimento continua onde está).
     */
    public function contact(Request $request, CityVisit $cityVisit, Client $client): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::enum(CrmChannel::class)],
            'result' => ['required', Rule::enum(CrmResult::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_at' => ['nullable', 'date', 'after_or_equal:today', Rule::requiredIf($request->input('result') === CrmResult::CallBack->value)],
            'appointment_date' => ['nullable', 'date', Rule::requiredIf($request->input('result') === CrmResult::Booked->value)],
        ], [
            'channel.required' => 'Escolha por onde foi o contato.',
            'result.required' => 'Escolha o resultado.',
            'follow_up_at.required' => 'Escolha o dia do retorno.',
            'follow_up_at.after_or_equal' => 'O retorno não pode ser antes de hoje.',
            'appointment_date.required' => 'Escolha o dia em que vai ao atendimento.',
        ]);
        $result = CrmResult::from($data['result']);
        if ($result === CrmResult::Booked) {
            self::assertWithinVisit($cityVisit, $data['appointment_date']);
        }

        $entry = DB::transaction(function () use ($request, $cityVisit, $client, $data, $result) {
            $entry = CrmEntry::for($cityVisit, $client);
            $user = $request->user();
            $entry->log(['user_id' => $user->id, 'kind' => 'contact', 'channel' => $data['channel'], 'result' => $result->value, 'notes' => $data['notes'] ?? null, 'follow_up_at' => $data['follow_up_at'] ?? null]);
            $entry->fill([
                'last_contact_at' => now(), 'last_result' => $result->value, 'last_user_id' => $user->id,
                'follow_up_at' => $result === CrmResult::CallBack ? $data['follow_up_at'] : null,
            ]);
            if ($result === CrmResult::Booked) {
                $entry->appointment_date = $data['appointment_date'];
            }
            $entry->save();
            if (! $entry->stage->wentToVisit()) {
                $entry->moveTo($result->stage(), $user);
            }

            return $entry;
        });

        return $this->client($cityVisit, $client)->setStatusCode(201);
    }

    /**
     * Muda a etapa à mão (arrastar no quadro ou o botão da etapa). "Marcou" aceita o dia (dentro do atendimento).
     */
    public function stage(Request $request, CityVisit $cityVisit, Client $client): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::enum(CrmStage::class)],
            'appointment_date' => ['nullable', 'date'],
        ]);
        $stage = CrmStage::from($data['stage']);
        if (! empty($data['appointment_date'])) {
            self::assertWithinVisit($cityVisit, $data['appointment_date']);
        }
        DB::transaction(function () use ($request, $cityVisit, $client, $stage, $data) {
            $entry = CrmEntry::for($cityVisit, $client);
            if ($stage === CrmStage::Booked && ! empty($data['appointment_date'])) {
                $entry->update(['appointment_date' => $data['appointment_date']]);
            }
            $entry->update(['follow_up_at' => $stage === CrmStage::Talking ? $entry->follow_up_at : null]);
            $entry->moveTo($stage, $request->user());
        });

        return $this->client($cityVisit, $client);
    }

    /**
     * Fim do atendimento: quem marcou e não foi vira "Não foi", de uma vez.
     */
    public function noShows(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $entries = CrmEntry::query()->where('city_visit_id', $cityVisit->id)->where('stage', CrmStage::Booked)->get();
        DB::transaction(fn () => $entries->each(fn (CrmEntry $e) => $e->moveTo(CrmStage::NoShow, $request->user(), 'auto', 'Marcou e não foi (fim do atendimento)')));

        return response()->json(['data' => ['count' => $entries->count()]]);
    }

    /**
     * Retornos para hoje ou atrasados, de todos os atendimentos da ótica (o mais atrasado primeiro).
     */
    public function followUps(): JsonResponse
    {
        $entries = CrmEntry::query()->with(['client', 'cityVisit.city', 'lastUser:id,name'])
            ->whereNotNull('follow_up_at')->whereDate('follow_up_at', '<=', today()->toDateString())
            ->where('stage', CrmStage::Talking)->orderBy('follow_up_at')->limit(200)->get();

        return response()->json(['data' => $entries->filter(fn (CrmEntry $e) => $e->client)->map(fn (CrmEntry $e) => [
            ...self::clientRow($e->client, $e),
            'visit' => self::visitData($e->cityVisit),
        ])->values()]);
    }

    /** @return array{0: string, 1: string, 2: string} 6 meses, 1 ano e 2 anos atrás */
    private static function thresholds(): array
    {
        $today = CarbonImmutable::today();

        return [$today->subMonths(6)->toDateString(), $today->subMonths(12)->toDateString(), $today->subMonths(24)->toDateString()];
    }

    private static function wherePeriod(Builder $query, string $period): void
    {
        [$m6, $y1, $y2] = self::thresholds();
        $column = 'clients.last_purchase_date';
        match ($period) {
            'ate_6m' => $query->where($column, '>=', $m6),
            '6m_1a' => $query->where($column, '>=', $y1)->where($column, '<', $m6),
            '1a_2a' => $query->where($column, '>=', $y2)->where($column, '<', $y1),
            'mais_2a' => $query->where($column, '<', $y2),
            default => $query->whereNull($column),
        };
    }

    private static function period(?CarbonImmutable $date): string
    {
        if (! $date) {
            return 'sem_data';
        }
        [$m6, $y1, $y2] = self::thresholds();
        $d = $date->toDateString();

        return match (true) {
            $d >= $m6 => 'ate_6m',
            $d >= $y1 => '6m_1a',
            $d >= $y2 => '1a_2a',
            default => 'mais_2a',
        };
    }

    private static function assertWithinVisit(CityVisit $visit, string $date): void
    {
        $day = CarbonImmutable::parse($date)->toDateString();
        if ($day < $visit->visit_date->toDateString() || $day > ($visit->end_date ?? $visit->visit_date)->toDateString()) {
            throw ValidationException::withMessages(['appointment_date' => 'Escolha um dia dentro do atendimento.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function clientRow(Client $client, ?CrmEntry $entry): array
    {
        $stage = $entry?->stage ?? CrmStage::ToContact;
        $last = $client->last_purchase_date ? CarbonImmutable::parse($client->last_purchase_date) : null;
        $period = self::period($last);

        return [
            'id' => $client->id,
            'name' => $client->name,
            ...PhonePrivacy::fields($client->phone_number),
            'last_purchase_date' => $last?->toDateString(),
            'last_purchase_amount' => $client->last_purchase_amount === null ? null : (float) $client->last_purchase_amount,
            'period' => $period,
            'period_label' => self::PERIODS[$period],
            'referred_by' => $client->referred_by,
            'stage' => $stage->value,
            'stage_label' => $stage->label(),
            'appointment_date' => $entry?->appointment_date?->toDateString(),
            'follow_up_at' => $entry?->follow_up_at?->toDateString(),
            'last_contact_at' => $entry?->last_contact_at?->toIso8601String(),
            'last_result' => $entry?->last_result,
            'last_result_label' => $entry?->last_result ? CrmResult::from($entry->last_result)->label() : null,
            'last_user' => $entry?->lastUser?->only(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityData(CrmActivity $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'channel' => $a->channel?->value,
            'channel_label' => $a->channel?->label(),
            'result' => $a->result?->value,
            'result_label' => $a->result?->label(),
            'from_stage' => $a->from_stage?->value,
            'from_stage_label' => $a->from_stage?->label(),
            'to_stage' => $a->to_stage?->value,
            'to_stage_label' => $a->to_stage?->label(),
            'notes' => $a->notes,
            'follow_up_at' => $a->follow_up_at?->toDateString(),
            'user' => $a->user?->only(['id', 'name']),
            'visit' => $a->cityVisit ? ['id' => $a->cityVisit->id, 'title' => $a->cityVisit->title, 'city' => $a->cityVisit->city?->name, 'visit_date' => $a->cityVisit->visit_date->toDateString()] : null,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
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
        ];
    }
}
