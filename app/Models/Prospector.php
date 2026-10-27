<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProspectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['city_id', 'name', 'birth_date', 'pix_key', 'phone_number', 'instagram_handle', 'blocked_at', 'trusted_at'])]
class Prospector extends Model
{
    /** @use HasFactory<ProspectorFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'blocked_at' => 'datetime',
            'trusted_at' => 'datetime',
        ];
    }

    /**
     * Bloqueado pela equipe: não entra no app nem envia indicações.
     */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Confiável: as indicações dele já nascem agendadas (no 1º dia livre do atendimento).
     */
    public function isTrusted(): bool
    {
        return $this->trusted_at !== null;
    }

    /**
     * Marca (ou desmarca) como confiável. Ao marcar, as indicações "Nova" dele com atendimento
     * ainda aberto passam para Agendada. Desmarcar não mexe nas indicações.
     */
    public function setTrusted(bool $trusted): void
    {
        if (! $trusted) {
            $this->update(['trusted_at' => null]);

            return;
        }

        if (! $this->isTrusted()) {
            $this->update(['trusted_at' => now()]);
        }

        $this->leads()
            ->where('stage', LeadStage::New)
            ->whereHas('visit', fn ($q) => $q->open())
            ->with('visit')
            ->get()
            ->each(fn (Lead $lead) => $lead->update([
                'stage' => LeadStage::Scheduled,
                'appointment_date' => $lead->visit->firstAvailableDay(),
            ]));
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Indicações feitas por este prospector.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Indicações que compareceram ao exame.
     *
     * @return HasMany<Lead, $this>
     */
    public function attendedLeads(): HasMany
    {
        return $this->leads()->where('stage', LeadStage::Attended);
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
