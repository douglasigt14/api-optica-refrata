<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\SessionUserResource;
use App\Models\Company;
use App\Models\Group;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Usuários da equipe vindos do Console (passo 7 do roteiro, token da plataforma). O Console manda a pessoa com as óticas
 * e o papel em cada uma e o hash da senha de lá (a mesma senha vale aqui); a lista de óticas substitui a de hoje. Sem
 * ótica nenhuma = sem acesso (as sessões caem; o usuário não é apagado). Quem nunca existiu aqui e não tem acesso não é
 * criado. Parceiros (login pelo telefone) ficam fora.
 * Liga pelo ID do Console (`console_id`); o usuário da equipe que já existia aqui sem ID é achado uma vez pelo e-mail.
 */
class PlatformUserController extends Controller
{
    public function upsert(Request $request, string $consoleId): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'array'],
            'group.console_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password_hash' => ['nullable', 'string', 'max:255'],
            'companies' => ['present', 'array'],
            'companies.*.console_id' => ['required', 'string', 'size:26'],
            'companies.*.roles' => ['required', 'array', 'size:1'],
            'companies.*.roles.*' => [Rule::enum(UserRole::class)],
        ]);
        $email = mb_strtolower(trim($data['email']));

        return DB::transaction(function () use ($data, $consoleId, $email) {
            $user = User::where('console_id', $consoleId)->first()
                ?? User::whereNull('console_id')->where('email', $email)->first();
            $group = Group::where('console_id', $data['group']['console_id'])->first();
            if (! $user && ! $data['companies']) {
                return response()->json(['data' => ['exists' => false]]);
            }
            if ($user && ! $user->isTeamMember()) {
                throw ValidationException::withMessages(['email' => "O e-mail {$email} é de um parceiro na ótica."]);
            }
            if (! $group) {
                throw ValidationException::withMessages(['group' => 'O grupo ainda não está na ótica: sincronize as empresas no Console.']);
            }
            if ($user && $user->group_id !== $group->id) {
                throw ValidationException::withMessages(['email' => "O e-mail {$email} já é de um usuário de outro grupo na ótica."]);
            }
            if (User::where('email', $email)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
                throw ValidationException::withMessages(['email' => "O e-mail {$email} já é de outro usuário na ótica."]);
            }
            if (! $user && blank($data['password_hash'] ?? null)) {
                throw ValidationException::withMessages(['password_hash' => 'Defina a senha no Console para dar o primeiro acesso à ótica.']);
            }

            $companies = [];
            foreach ($data['companies'] as $access) {
                $company = Company::where('group_id', $group->id)->where('console_id', $access['console_id'])->first()
                    ?? throw ValidationException::withMessages(['companies' => 'Uma das óticas ainda não está no sistema da ótica: sincronize a empresa no Console.']);
                $companies[$company->id] = ['role' => $access['roles'][0]];
            }

            $user ??= new User;
            $user->fill(['group_id' => $group->id, 'name' => $data['name'], 'email' => $email]);
            $user->console_id = $consoleId;
            if (filled($data['password_hash'] ?? null)) {
                $user->password = self::hash($data['password_hash']);
            }
            $user->save();
            $user->companies()->sync($companies);
            if (! $companies) {
                $user->tokens()->delete();
            }

            return response()->json(['data' => ['exists' => true, 'id' => $user->id]]);
        });
    }

    /**
     * Equipe do grupo (sem os parceiros), com as óticas (só as que já vieram do Console) e o papel: para o Console importar.
     */
    public function index(string $groupConsoleId): JsonResponse
    {
        $group = Group::where('console_id', $groupConsoleId)->first();

        return response()->json(['data' => $group ? User::with('companies')->where('group_id', $group->id)->whereNull('prospector_id')->whereNotNull('email')->orderBy('name')->get()->map(fn (User $user) => [
            'console_id' => $user->console_id,
            'name' => $user->name,
            'email' => $user->email,
            'companies' => $user->companies->whereNotNull('console_id')->map(fn (Company $company) => [
                'console_id' => $company->console_id,
                'roles' => [$company->pivot->role->value],
            ])->values()->all(),
        ])->values()->all() : []]);
    }

    /**
     * Login único (passo 4 do roteiro, pelo painel único `app.refrataopto.com.br`): o Console já conferiu e-mail e senha
     * e pede aqui um token da pessoa, pela ligação da tela Módulos. Vale o mesmo que no login daqui: só a equipe (parceiro não) e sem ótica disponível, não entra.
     */
    public function token(Request $request, string $consoleId): JsonResponse
    {
        $device = $request->validate(['device_name' => ['nullable', 'string', 'max:100']])['device_name'] ?? null;
        $user = User::where('console_id', $consoleId)->whereNull('prospector_id')->first();
        abort_if($user === null, 404, 'Esta pessoa não tem acesso a este sistema.');

        $companies = $user->load('companies.group')->availableCompanies();
        if ($companies->isEmpty()) {
            throw ValidationException::withMessages(['user' => 'Acesso bloqueado. Fale com o administrador da ótica.']);
        }
        if ($companies->count() === 1) {
            app(CurrentCompany::class)->set($companies->first());
        }

        $token = $user->createToken($device ?: 'painel único');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->accessToken,
            'expires_at' => $token->token->expires_at?->toIso8601String(),
            'user' => new SessionUserResource($user),
        ]);
    }

    /**
     * Só aceita hash bcrypt (o que o Console usa); a senha em texto nunca vem.
     */
    private static function hash(string $hash): string
    {
        if (! Hash::isHashed($hash) || password_get_info($hash)['algoName'] !== 'bcrypt') {
            throw ValidationException::withMessages(['password_hash' => 'Senha em formato inválido.']);
        }

        return $hash;
    }
}
