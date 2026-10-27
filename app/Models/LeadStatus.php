<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Enums\LeadStatusColor;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Situação da indicação, criada pela equipe dentro de uma etapa fixa (LeadStage).
 * Cada etapa tem exatamente uma situação padrão, usada nas mudanças automáticas.
 */
#[Fillable(['name', 'partner_name', 'stage', 'color', 'sort_order', 'is_default', 'active'])]
class LeadStatus extends Model
{
    use BelongsToCompany;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'color' => LeadStatusColor::class,
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Situação padrão da etapa (indicação nova, 1º contato, dia marcado…).
     */
    public static function defaultFor(LeadStage $stage): self
    {
        return static::query()->where('stage', $stage)->where('is_default', true)->firstOrFail();
    }

    /**
     * Na ordem das etapas e, dentro delas, na ordem escolhida pela equipe.
     *
     * @param  Builder<LeadStatus>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $stages = array_map(fn (LeadStage $stage) => $stage->value, LeadStage::cases());
        $query->orderByRaw('CASE stage '.implode(' ', array_map(fn ($stage, $i) => "WHEN '{$stage}' THEN {$i}", $stages, array_keys($stages))).' END')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
