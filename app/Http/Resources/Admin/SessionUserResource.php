<?php

namespace App\Http\Resources\Admin;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/**
 * Quem está logado no painel (login e `/admin/me`): o usuário, as óticas em que pode entrar (com o papel em cada uma e,
 * para o admin, o código de convite dos parceiros e o painel do financeiro, `financial_url`, para o item Financeiro no
 * menu) e a ótica escolhida (`company_id`; null = ainda precisa escolher).
 *
 * @mixin User
 */
class SessionUserResource extends TeamUserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'company_id' => app(CurrentCompany::class)->id(),
            'companies' => $this->availableCompanies()->map(function (Company $company) {
                $role = $this->roleIn($company);

                return [
                    'id' => $company->id,
                    'name' => $company->displayName(),
                    'role' => $role,
                    'role_label' => $role?->label(),
                    'console_id' => $company->console_id,
                    'invite_code' => $role === UserRole::Admin ? $company->invite_code : null,
                    'financial_url' => $role === UserRole::Admin ? $company->financial_url : null,
                ];
            })->values(),
        ];
    }
}
