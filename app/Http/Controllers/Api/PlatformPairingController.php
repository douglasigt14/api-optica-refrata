<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pareamento com o Console da Refrata (tela Módulos de lá), sem `.env`: o Console confere qual sistema é e se já está
 * pareado (`GET /platform/pair`), manda a chave nova (`POST`, aceito só enquanto não há nenhuma: depois, 409), testa
 * (`GET /platform/ping`) e troca a chave (`PUT /platform/token`, com a chave atual).
 */
class PlatformPairingController extends Controller
{
    private const MODULE = 'optica';

    public function status(): JsonResponse
    {
        return response()->json(['data' => ['module' => self::MODULE, 'paired' => PlatformAccess::current() !== null]]);
    }

    public function pair(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'min:40', 'max:255']])['token'];

        $access = DB::transaction(function () use ($token) {
            if (PlatformAccess::query()->lockForUpdate()->exists()) {
                return null;
            }

            return PlatformAccess::create(['token_hash' => PlatformAccess::hash($token), 'paired_at' => now()]);
        });
        abort_if($access === null, 409, 'Este sistema já está pareado com o Console.');

        return response()->json(['data' => $this->data($access)], 201);
    }

    public function ping(): JsonResponse
    {
        return response()->json(['data' => $this->data(PlatformAccess::current())]);
    }

    public function rotate(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'min:40', 'max:255']])['token'];
        $access = PlatformAccess::current();
        $access->update(['token_hash' => PlatformAccess::hash($token), 'paired_at' => now()]);

        return response()->json(['data' => $this->data($access)]);
    }

    /**
     * @return array{module: string, paired_at: string|null}
     */
    private function data(?PlatformAccess $access): array
    {
        return ['module' => self::MODULE, 'paired_at' => $access?->paired_at?->toIso8601String()];
    }
}
