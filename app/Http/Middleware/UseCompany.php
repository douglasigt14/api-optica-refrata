<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Escolhe a ótica da requisição. Parceiro: a ótica do cadastro dele. Equipe: o cabeçalho `X-Company` (id), conferido
 * contra os acessos; sem ele, a única ótica da pessoa. Sem ótica disponível (inativa, grupo bloqueado), 403. Roda antes
 * do route model binding, para o registro de outra ótica dar 404. `use-company:optional` (o `/admin/me`): com mais de uma
 * ótica e sem escolha, segue sem ótica.
 */
class UseCompany
{
    public function __construct(private CurrentCompany $current) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        $user->load(['companies.group', 'prospector.company.group']);
        $companies = $user->availableCompanies();
        abort_if($companies->isEmpty(), 403, 'Acesso bloqueado. Fale com a equipe da ótica.');

        $chosen = $user->isTeamMember() ? $request->header('X-Company') : null;
        if (filled($chosen)) {
            $company = $companies->firstWhere('id', (int) $chosen);
            abort_unless($company, 403, 'Você não tem acesso a esta ótica.');
        } elseif ($companies->count() === 1) {
            $company = $companies->first();
        } else {
            abort_unless($mode === 'optional', 409, 'Escolha a ótica.');
            $company = null;
        }

        $this->current->set($company);

        return $next($request);
    }
}
