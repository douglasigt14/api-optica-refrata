<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Prospector;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Cadastro do prospector na ótica do convite (`invite_code`, conferido no RegisterRequest, que também escolhe a ótica):
     * cria o prospector e o usuário dele e já devolve o token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $prospector = Prospector::create($request->safe()->only([
                'city_id', 'name', 'birth_date', 'pix_key', 'phone_number', 'instagram_handle',
            ]));

            return $prospector->user()->create([
                'phone_number' => $prospector->phone_number,
                'password' => $request->validated('password'),
            ]);
        });

        return $this->tokenResponse($user, $request->validated('device_name'), Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('phone_number', $request->validated('phone_number'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone_number' => 'Telefone ou senha incorretos.',
            ]);
        }

        // Ótica inativa ou grupo bloqueado no Console: nenhum parceiro dela entra.
        if ($user->prospector && ! $user->prospector->company?->available()) {
            throw ValidationException::withMessages([
                'phone_number' => 'Acesso bloqueado. Fale com a equipe da ótica.',
            ]);
        }

        if ($user->prospector?->isBlocked()) {
            throw ValidationException::withMessages([
                'phone_number' => 'Seu cadastro está bloqueado. Fale com a equipe da ótica.',
            ]);
        }

        return $this->tokenResponse($user, $request->validated('device_name'));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('prospector.city', 'prospector.company'));
    }

    public function logout(Request $request): Response
    {
        $request->user()->token()->revoke();

        return response()->noContent();
    }

    private function tokenResponse(User $user, ?string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        $token = $user->createToken($deviceName ?: 'app');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->accessToken,
            'expires_at' => $token->token->expires_at?->toIso8601String(),
            'user' => new UserResource($user->load('prospector.city', 'prospector.company')),
        ], $status);
    }
}
