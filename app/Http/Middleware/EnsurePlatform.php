<?php

namespace App\Http\Middleware;

use App\Models\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada do Console da Refrata (plataforma): `Authorization: Bearer <chave>`. A chave é da instalação (não de um
 * cliente) e fica no banco (`PlatformAccess`, pareada pela tela Módulos do Console). Sem pareamento = 503.
 */
class EnsurePlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(PlatformAccess::current() === null, 503, 'Sistema ainda não pareado com o Console.');
        abort_unless(PlatformAccess::accepts((string) $request->bearerToken()), 401, 'Token da plataforma inválido.');

        return $next($request);
    }
}
