<?php

namespace App\Models;

use App\Enums\CrmStage;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O cliente num atendimento, no CRM (12/11/2026): a etapa (`CrmStage`), o dia marcado, o retorno e o último contato.
 */
#[Fillable(['city_visit_id', 'client_id', 'stage', 'appointment_date', 'follow_up_at', 'last_contact_at', 'last_result', 'last_user_id', 'stage_changed_at'])]
class CrmEntry extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'stage' => CrmStage::class,
            'appointment_date' => 'date',
            'follow_up_at' => 'date',
            'last_contact_at' => 'datetime',
            'stage_changed_at' => 'datetime',
        ];
    }

    /** A linha do cliente no atendimento (nasce "A contatar" no 1º movimento). */
    public static function for(CityVisit $visit, Client $client): self
    {
        return static::firstOrCreate(['city_visit_id' => $visit->id, 'client_id' => $client->id], ['stage' => CrmStage::ToContact]);
    }

    /**
     * Muda a etapa e registra no histórico (`kind`: `stage` = à mão, `auto` = pelo sistema).
     */
    public function moveTo(CrmStage $stage, ?User $user, string $kind = 'stage', ?string $notes = null): void
    {
        $from = $this->stage;
        if ($from === $stage) {
            return;
        }
        $this->stage = $stage;
        $this->stage_changed_at = now();
        if ($stage !== CrmStage::Booked && ! $stage->hasBooked()) {
            $this->appointment_date = null;
        }
        $this->save();
        $this->log(['user_id' => $user?->id, 'kind' => $kind, 'from_stage' => $from?->value, 'to_stage' => $stage->value, 'notes' => $notes]);
    }

    /**
     * Grava uma linha no histórico (na empresa da linha, mesmo fora de uma requisição).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function log(array $attributes): CrmActivity
    {
        $activity = new CrmActivity(['city_visit_id' => $this->city_visit_id, 'client_id' => $this->client_id, ...$attributes]);
        $activity->company_id = $this->company_id;
        $this->activities()->save($activity);

        return $activity;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<CityVisit, $this>
     */
    public function cityVisit(): BelongsTo
    {
        return $this->belongsTo(CityVisit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_user_id');
    }

    /**
     * @return HasMany<CrmActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class);
    }
}
