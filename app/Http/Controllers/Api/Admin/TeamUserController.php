<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeamUserRequest;
use App\Http\Resources\Admin\TeamUserResource;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Usuários da equipe da ótica escolhida (só Admin). A pessoa é do grupo; o papel é desta ótica. Parceiros não aparecem
 * nem podem ser editados aqui.
 */
class TeamUserController extends Controller
{
    public function __construct(private CurrentCompany $current) {}

    public function index(): AnonymousResourceCollection
    {
        return TeamUserResource::collection($this->current->require()->users()->whereNull('prospector_id')->with('companies')->orderBy('name')->get());
    }

    /**
     * Cadastra com o papel nesta ótica. E-mail de alguém da equipe do grupo: só ganha acesso a esta ótica.
     */
    public function store(TeamUserRequest $request): JsonResponse
    {
        $company = $this->current->require();
        $data = $request->validated();

        $user = DB::transaction(function () use ($company, $data) {
            $user = User::where('email', $data['email'])->whereNull('prospector_id')->first();
            if ($user && $user->companies()->whereKey($company->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Esta pessoa já tem acesso a esta ótica.']);
            }
            $user ??= User::create([...array_diff_key($data, ['role' => true]), 'group_id' => $company->group_id]);
            $user->companies()->attach($company->id, ['role' => $data['role']]);

            return $user;
        });

        return (new TeamUserResource($user->load('companies')))->response()->setStatusCode(201);
    }

    public function update(TeamUserRequest $request, User $user): TeamUserResource
    {
        $company = $this->current->require();
        abort_unless($user->isTeamMember() && $user->companies->contains('id', $company->id), 404);
        $data = array_filter($request->validated(), fn ($value, $key) => $key !== 'password' || filled($value), ARRAY_FILTER_USE_BOTH);

        if (isset($data['role'])) {
            if ($request->user()->is($user) && $data['role'] !== $user->role?->value) {
                throw ValidationException::withMessages(['role' => 'Você não pode mudar o seu próprio papel.']);
            }
            $user->companies()->updateExistingPivot($company->id, ['role' => $data['role']]);
            unset($data['role']);
        }
        $user->update($data);

        return new TeamUserResource($user->load('companies'));
    }

    /**
     * Tira o acesso da pessoa a esta ótica e derruba as sessões dela; sem acesso a nenhuma outra, o usuário é removido.
     * Não dá para remover a si mesmo.
     */
    public function destroy(Request $request, User $user): Response
    {
        $company = $this->current->require();
        abort_unless($user->isTeamMember() && $user->companies->contains('id', $company->id), 404);
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'Você não pode remover o seu próprio usuário.']);
        }

        $user->tokens()->delete();
        $user->companies()->detach($company->id);
        if (! $user->companies()->exists()) {
            $user->delete();
        }

        return response()->noContent();
    }
}
