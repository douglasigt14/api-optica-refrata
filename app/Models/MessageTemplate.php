<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de mensagem da ótica (CRM, 12/11/2026), com `{nome}`, `{cidade}`, `{data_atendimento}` e `{otica}` preenchidos
 * na hora de enviar.
 */
#[Fillable(['name', 'body', 'active'])]
class MessageTemplate extends Model
{
    use BelongsToCompany;

    public const VARIABLES = ['{nome}', '{cidade}', '{data_atendimento}', '{otica}'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** O texto com as variáveis preenchidas. */
    public function render(?string $name, ?CityVisit $visit, ?Company $company): string
    {
        $period = $visit ? ($visit->end_date && ! $visit->end_date->isSameDay($visit->visit_date)
            ? $visit->visit_date->format('d/m').' a '.$visit->end_date->format('d/m')
            : $visit->visit_date->format('d/m')) : '';

        return strtr($this->body, [
            '{nome}' => trim(explode(' ', (string) $name)[0] ?? ''),
            '{cidade}' => $visit?->city?->name ?? '',
            '{data_atendimento}' => $period,
            '{otica}' => $company?->trade_name ?: ($company?->name ?? ''),
        ]);
    }
}
