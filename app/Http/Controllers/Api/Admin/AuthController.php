<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Http\Resources\Admin\SessionUserResource;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login da equipe interna por e-mail e senha. Parceiros (sem papel) não entram aqui.
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->whereNull('prospector_id')->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha incorretos.',
            ]);
        }

        // Sem ótica disponível (todas desativadas ou grupo bloqueado no Console): não entra.
        $companies = $user->load('companies.group')->availableCompanies();
        if ($companies->isEmpty()) {
            throw ValidationException::withMessages(['email' => 'Acesso bloqueado. Fale com o administrador da ótica.']);
        }
        if ($companies->count() === 1) {
            app(CurrentCompany::class)->set($companies->first());
        }

        $token = $user->createToken($request->validated('device_name') ?: 'admin');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->accessToken,
            'expires_at' => $token->token->expires_at?->toIso8601String(),
            'user' => new SessionUserResource($user),
        ]);
    }

    public function me(Request $request): SessionUserResource
    {
        return new SessionUserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->token()->revoke();

        return response()->noContent();
    }
}
