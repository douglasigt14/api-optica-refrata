<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Indicação feita por um prospector. Ainda não é cliente: quando virar, o cliente aponta para cá (clients.lead_id).
 * Apagar só preenche deleted_at (soft delete).
 *
 * Situação: lead_status_id (editável pela equipe). A etapa (stage) acompanha a situação sozinha, para as regras
 * e contagens. Dá para mudar por qualquer um dos dois: só a etapa → vai para a situação padrão dela (mantém a
 * atual se já for da mesma etapa). Cada mudança fica em lead_status_changes.
 */
#[Fillable(['city_id', 'city_visit_id', 'name', 'phone_number', 'stage', 'lead_status_id', 'appointment_date'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            if ($lead->isDirty('lead_status_id') && $lead->lead_status_id !== null) {
                $lead->stage = LeadStatus::findOrFail($lead->lead_status_id)->stage;
            } elseif ($lead->lead_status_id === null || ($lead->isDirty('stage') && $lead->status?->stage !== $lead->stage)) {
                $lead->lead_status_id = LeadStatus::defaultFor($lead->stage ?? LeadStage::New)->id;
                $lead->stage = $lead->stage ?? LeadStage::New;
            }
            $lead->unsetRelation('status');
        });

        static::saved(function (Lead $lead) {
            if ($lead->wasRecentlyCreated || $lead->wasChanged('lead_status_id')) {
                $lead->statusChanges()->create([
                    'from_lead_status_id' => $lead->wasRecentlyCreated ? null : $lead->getOriginal('lead_status_id'),
                    'to_lead_status_id' => $lead->lead_status_id,
                    'user_id' => auth()->id(),
                ]);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'appointment_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Prospector, $this>
     */
    public function prospector(): BelongsTo
    {
        return $this->belongsTo(Prospector::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Atendimento na cidade ao qual a indicação foi vinculada.
     *
     * @return BelongsTo<CityVisit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(CityVisit::class, 'city_visit_id');
    }

    /**
     * Contatos da equipe com a pessoa indicada, do mais recente para o mais antigo.
     *
     * @return HasMany<LeadContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(LeadContact::class)->latest()->latest('id');
    }

    /**
     * Situação atual (dentro da etapa).
     *
     * @return BelongsTo<LeadStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    /**
     * Mudanças de situação, da mais recente para a mais antiga.
     *
     * @return HasMany<LeadStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(LeadStatusChange::class)->latest()->latest('id');
    }

    /**
     * @return HasOne<Client, $this>
     */
    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }
}
