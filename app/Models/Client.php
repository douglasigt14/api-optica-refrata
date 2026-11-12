<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente da ótica: veio de uma indicação (`lead_id`) ou foi cadastrado na tela Clientes; é o paciente dos pedidos ao
 * laboratório.
 */
#[Fillable(['lead_id', 'city_id', 'name', 'cpf', 'birth_date', 'age', 'wears_glasses', 'phone_number', 'address', 'comorbidities', 'referral_source', 'last_purchase_date', 'last_purchase_amount', 'referred_by', 'notes'])]
class Client extends Model
{
    use BelongsToCompany;

    /** Comorbidades do cadastro (10/11/2026): cada uma na própria pessoa (`self`) e/ou na família (`family`). */
    public const COMORBIDITIES = ['diabetes', 'hypertension', 'glaucoma', 'blindness'];

    public const COMORBIDITY_WHO = ['self', 'family'];

    /** Como conheceu a ótica ("Por que você veio?"). */
    public const REFERRAL_SOURCES = ['instagram', 'partner', 'ad'];

    /**
     * Com data de nascimento, a idade sai dela (a informada some). Sem data, a idade digitada vale a partir do dia em
     * que foi informada.
     */
    protected static function booted(): void
    {
        static::saving(function (Client $client) {
            if ($client->birth_date) {
                $client->age = null;
                $client->age_recorded_on = null;
            } elseif ($client->age === null) {
                $client->age_recorded_on = null;
            } elseif ($client->isDirty('age') || ! $client->age_recorded_on) {
                $client->age_recorded_on = now()->toDateString();
            }
        });
    }

    /** Idade hoje: pela data de nascimento ou pela informada (mais os anos que passaram desde então). */
    public function currentAge(): ?int
    {
        if ($this->birth_date) {
            return (int) $this->birth_date->diffInYears(now());
        }
        if ($this->age === null) {
            return null;
        }

        return $this->age + ($this->age_recorded_on ? (int) $this->age_recorded_on->diffInYears(now()) : 0);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'age' => 'integer',
            'wears_glasses' => 'boolean',
            'age_recorded_on' => 'date',
            'comorbidities' => 'array',
            'last_purchase_date' => 'date',
            'last_purchase_amount' => 'decimal:2',
        ];
    }

    /**
     * Indicação que originou o cliente, se houver.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Pedidos ao laboratório deste cliente.
     *
     * @return HasMany<LabOrder, $this>
     */
    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }
}
