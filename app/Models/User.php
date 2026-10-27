<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\CurrentCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Login do parceiro (tem `prospector_id`; a ótica dele é a do cadastro de parceiro) ou de alguém da equipe (de **um**
 * grupo, com um papel em cada ótica do grupo em que tem acesso). `role` = o papel na ótica escolhida.
 */
#[Fillable(['group_id', 'prospector_id', 'name', 'email', 'phone_number', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Usuário da equipe interna. Parceiros (com cadastro de parceiro) não são.
     */
    public function isTeamMember(): bool
    {
        return $this->prospector_id === null;
    }

    /**
     * Tem um dos papéis na ótica escolhida.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * @return BelongsTo<Prospector, $this>
     */
    public function prospector(): BelongsTo
    {
        return $this->belongsTo(Prospector::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Óticas em que a pessoa da equipe tem acesso, com o papel (`pivot.role`).
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->using(CompanyUser::class)->withPivot('role')->withTimestamps()->orderBy('name');
    }

    /**
     * Óticas em que pode entrar agora (ativas e de grupo não bloqueado). O parceiro: a ótica do cadastro dele.
     *
     * @return Collection<int, Company>
     */
    public function availableCompanies(): Collection
    {
        $companies = $this->prospector_id
            ? Collection::make(array_filter([$this->prospector?->company]))
            : $this->companies;

        return $companies->filter(fn (Company $company) => $company->available())->values();
    }

    /**
     * Papel na ótica (null = sem acesso ou parceiro).
     */
    public function roleIn(Company|int|null $company): ?UserRole
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $id ? $this->companies->firstWhere('id', $id)?->pivot->role : null;
    }

    /**
     * Papel na ótica escolhida.
     *
     * @return Attribute<UserRole|null, never>
     */
    protected function role(): Attribute
    {
        return Attribute::get(fn () => $this->roleIn(app(CurrentCompany::class)->id()))->withoutObjectCaching();
    }
}
