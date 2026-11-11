<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LeadStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScheduleLeadRequest;
use App\Http\Requests\Admin\StoreLeadContactRequest;
use App\Http\Requests\Admin\StoreLeadRequest;
use App\Http\Requests\Admin\UpdateLeadStatusRequest;
use App\Http\Resources\Admin\LeadContactResource;
use App\Http\Resources\Admin\LeadResource;
use App\Models\CityVisit;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Prospector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /**
     * Lista global de indicações, paginada, com filtros e busca por nome ou telefone. Mais recentes primeiro.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'city_id' => ['nullable', 'integer'],
            'city_visit_id' => ['nullable', 'integer'],
            'prospector_id' => ['nullable', 'integer'],
            'stage' => ['nullable', Rule::enum(LeadStage::class)],
            'lead_status_id' => ['nullable', 'integer'],
            'appointment_date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $leads = Lead::query()
            ->with(['city', 'visit', 'prospector', 'status'])
            ->withCount('contacts')
            ->when($filters['city_id'] ?? null, fn ($q, $id) => $q->where('city_id', $id))
            ->when($filters['city_visit_id'] ?? null, fn ($q, $id) => $q->where('city_visit_id', $id))
            ->when($filters['prospector_id'] ?? null, fn ($q, $id) => $q->where('prospector_id', $id))
            ->when($filters['stage'] ?? null, fn ($q, $stage) => $q->where('stage', $stage))
            ->when($filters['lead_status_id'] ?? null, fn ($q, $id) => $q->where('lead_status_id', $id))
            ->when($filters['appointment_date'] ?? null, fn ($q, $date) => $q->whereDate('appointment_date', $date))
            ->when(trim($filters['search'] ?? ''), function ($q, string $search) {
                $digits = preg_replace('/\D/', '', $search);
                $q->where(function ($q) use ($search, $digits) {
                    $q->where('name', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $q->orWhere('phone_number', 'like', "%{$digits}%");
                    }
                });
            })
            ->latest()
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return LeadResource::collection($leads);
    }

    /**
     * Nova indicação lançada pela equipe em nome de um parceiro (11/11/2026). Segue a regra do app dos parceiros:
     * de parceiro confiável, já nasce Agendada no 1º dia livre do atendimento.
     */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data = $request->validated();
        $prospector = Prospector::findOrFail($data['prospector_id']);
        unset($data['prospector_id']);

        if ($prospector->isTrusted()) {
            $data['stage'] = LeadStage::Scheduled;
            $data['appointment_date'] = CityVisit::findOrFail($data['city_visit_id'])->firstAvailableDay();
        }

        $lead = $prospector->leads()->create($data);

        return (new LeadResource($lead->load(['city', 'visit', 'prospector', 'status'])->loadCount('contacts')))->response()->setStatusCode(201);
    }

    public function show(Lead $lead): LeadResource
    {
        return new LeadResource($lead->load(['city', 'visit', 'prospector', 'status', 'contacts.user', 'statusChanges.from', 'statusChanges.to', 'statusChanges.user.prospector']));
    }

    /**
     * Marca o dia do exame (vai para a etapa Agendada) ou desmarca com null (volta para Em contato).
     * Se já está numa situação da etapa certa, continua nela.
     */
    public function schedule(ScheduleLeadRequest $request, Lead $lead): LeadResource
    {
        $date = $request->validated('appointment_date');

        $lead->update([
            'appointment_date' => $date,
            'stage' => $date ? LeadStage::Scheduled : LeadStage::Contacting,
        ]);

        return $this->show($lead);
    }

    /**
     * Muda a situação. Ir para uma etapa sem exame (Nova, Em contato, Descartada) desmarca o dia.
     */
    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead): LeadResource
    {
        $status = LeadStatus::findOrFail($request->validated('lead_status_id'));

        $lead->update([
            'lead_status_id' => $status->id,
            'appointment_date' => $status->stage->needsAppointment() ? $lead->appointment_date : null,
        ]);

        return $this->show($lead);
    }

    /**
     * Registra um contato da equipe. Indicação "Nova" passa para "Em contato".
     */
    public function storeContact(StoreLeadContactRequest $request, Lead $lead): JsonResponse
    {
        $contact = $lead->contacts()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        if ($lead->stage === LeadStage::New) {
            $lead->update(['stage' => LeadStage::Contacting]);
        }

        return (new LeadContactResource($contact->load('user')))->response()->setStatusCode(201);
    }
}
