<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Senha da fila de um atendimento (12/11/2026): normal (N001…) ou prioridade (P001…), numerada do 1 em cada atendimento.
 */
#[Fillable(['city_visit_id', 'type', 'number', 'called_at', 'user_id', 'called_by'])]
class QueueTicket extends Model
{
    use BelongsToCompany;

    public const TYPES = ['normal' => 'N', 'priority' => 'P'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'called_at' => 'datetime',
        ];
    }

    /** "N001", "P012"… */
    public function code(): string
    {
        return self::TYPES[$this->type].str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<CityVisit, $this>
     */
    public function cityVisit(): BelongsTo
    {
        return $this->belongsTo(CityVisit::class);
    }
}
