<?php

namespace App\Models;

use App\Support\CurrentCompany;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Município do IBGE (lista comum a todas as óticas). Cada ótica escolhe as que atende (`company_city`).
 */
#[Fillable(['ibge_code', 'name', 'state'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    /**
     * Só as cidades que a ótica atende (a escolhida, se nenhuma for informada). Sem ótica, nenhuma.
     *
     * @param  Builder<City>  $query
     */
    #[Scope]
    protected function servedBy(Builder $query, Company|int|null $company = null): void
    {
        $id = $company instanceof Company ? $company->id : ($company ?? app(CurrentCompany::class)->id());
        $query->whereIn($query->qualifyColumn('id'), CompanyCity::query()->select('city_id')->where('company_id', $id ?? 0));
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_city')->using(CompanyCity::class)->withTimestamps();
    }

    /**
     * @return HasMany<Prospector, $this>
     */
    public function prospectors(): HasMany
    {
        return $this->hasMany(Prospector::class);
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<CityVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(CityVisit::class);
    }
}
