<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada do Console da Refrata (plataforma): `Authorization: Bearer <PLATFORM_TOKEN>`. É o token da instalação
 * (infraestrutura, não de um cliente), o mesmo no .env do Console. Vazio = desligado (503).
 */
class EnsurePlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.platform.token');
        abort_if($token === '', 503, 'Plataforma não configurada (PLATFORM_TOKEN).');
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401, 'Token da plataforma inválido.');

        return $next($request);
    }
}
