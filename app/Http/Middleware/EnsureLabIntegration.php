<?php

namespace App\Http\Middleware;

use App\Models\LabConnection;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada de um laboratório do grupo (aviso de situação): `Authorization: Bearer <chave>` gravada pelo Console. A chave
 * diz a ótica (empresa) e o laboratório.
 */
class EnsureLabIntegration
{
    public function __construct(private CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $lab = $token === '' ? null : LabConnection::withoutGlobalScopes()->with('company')->firstWhere('inbound_token_hash', LabConnection::hash($token));
        abort_unless($lab, 401, 'Chave de integração inválida.');

        $this->current->set($lab->company);
        $request->attributes->set('lab', $lab);

        return $next($request);
    }
}
