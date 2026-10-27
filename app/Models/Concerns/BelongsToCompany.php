<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dado de uma empresa: as consultas só enxergam a empresa escolhida (o de outra dá 404 no route model binding) e o
 * registro novo nasce nela.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if ($id = app(CurrentCompany::class)->id()) {
                $query->where($query->qualifyColumn('company_id'), $id);
            }
        });

        static::creating(function ($model) {
            $model->company_id ??= app(CurrentCompany::class)->require()->id;
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
