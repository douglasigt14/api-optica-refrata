<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProspectorRequest;
use App\Http\Resources\Admin\ProspectorResource;
use App\Models\Prospector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Parceiros (prospectors). Admin e atendente consultam e marcam como confiável; só o Admin cadastra, edita,
 * bloqueia e troca a senha.
 */
class ProspectorController extends Controller
{
    /**
     * Lista paginada com busca (nome ou telefone), cidade e situação (ativo/bloqueado), em ordem alfabética.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city_id' => ['nullable', 'integer'],
            'blocked' => ['nullable', 'boolean'],
            'trusted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $prospectors = Prospector::query()
            ->with('city:id,name,state')
            ->withCount(['leads', 'attendedLeads'])
            ->when($filters['city_id'] ?? null, fn ($q, $id) => $q->where('city_id', $id))
            ->when(isset($filters['blocked']), fn ($q) => $request->boolean('blocked') ? $q->whereNotNull('blocked_at') : $q->whereNull('blocked_at'))
            ->when(isset($filters['trusted']), fn ($q) => $request->boolean('trusted') ? $q->whereNotNull('trusted_at') : $q->whereNull('trusted_at'))
            ->when(trim($filters['search'] ?? ''), function ($q, string $search) {
                $digits = preg_replace('/\D/', '', $search);
                $q->where(function ($q) use ($search, $digits) {
                    $q->where('name', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $q->orWhere('phone_number', 'like', "%{$digits}%");
                    }
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ProspectorResource::collection($prospectors);
    }

    /**
     * Lista curta de todos os parceiros, para os filtros das outras telas.
     */
    public function options(): JsonResponse
    {
        $prospectors = Prospector::with('city:id,name,state')
            ->withCount('leads')
            ->orderBy('name')
            ->get()
            ->map(fn (Prospector $prospector) => [
                'id' => $prospector->id,
                'name' => $prospector->name,
                'phone_number' => $prospector->phone_number,
                'city' => $prospector->city->only(['id', 'name', 'state']),
                'leads_count' => $prospector->leads_count,
                'blocked' => $prospector->isBlocked(),
            ]);

        return response()->json(['data' => $prospectors]);
    }

    public function show(Prospector $prospector): ProspectorResource
    {
        return new ProspectorResource($this->forDetail($prospector));
    }

    /**
     * Cadastro pela equipe: cria o parceiro e o usuário dele (login = telefone) com a senha inicial.
     */
    public function store(ProspectorRequest $request): JsonResponse
    {
        $prospector = DB::transaction(function () use ($request) {
            $prospector = Prospector::create($request->safe()->except(['password', 'trusted']));
            $prospector->user()->create([
                'phone_number' => $prospector->phone_number,
                'password' => $request->validated('password'),
            ]);
            if ($request->boolean('trusted')) {
                $prospector->setTrusted(true);
            }

            return $prospector;
        });

        return (new ProspectorResource($this->forDetail($prospector)))->response()->setStatusCode(201);
    }

    /**
     * Edita só o que for enviado. Mudar o telefone muda também o login do parceiro.
     */
    public function update(ProspectorRequest $request, Prospector $prospector): ProspectorResource
    {
        DB::transaction(function () use ($request, $prospector) {
            $prospector->update($request->safe()->except('trusted'));
            if ($request->has('trusted')) {
                $prospector->setTrusted($request->boolean('trusted'));
            }

            if ($prospector->wasChanged('phone_number')) {
                $prospector->user?->update(['phone_number' => $prospector->phone_number]);
            }
        });

        return new ProspectorResource($this->forDetail($prospector));
    }

    /**
     * Bloqueia: o parceiro sai do app na hora (sessões derrubadas) e não consegue entrar nem indicar.
     */
    public function block(Prospector $prospector): ProspectorResource
    {
        if (! $prospector->isBlocked()) {
            $prospector->update(['blocked_at' => now()]);
        }
        $prospector->user?->tokens()->delete();

        return new ProspectorResource($this->forDetail($prospector));
    }

    public function unblock(Prospector $prospector): ProspectorResource
    {
        $prospector->update(['blocked_at' => null]);

        return new ProspectorResource($this->forDetail($prospector));
    }

    /**
     * Marca como confiável: as indicações dele passam a nascer agendadas, e as "Nova" de agora são agendadas.
     */
    public function trust(Prospector $prospector): ProspectorResource
    {
        DB::transaction(fn () => $prospector->setTrusted(true));

        return new ProspectorResource($this->forDetail($prospector));
    }

    /**
     * Deixa de ser confiável. As indicações já agendadas continuam como estão.
     */
    public function untrust(Prospector $prospector): ProspectorResource
    {
        $prospector->setTrusted(false);

        return new ProspectorResource($this->forDetail($prospector));
    }

    /**
     * Nova senha definida pela equipe (enquanto não há recuperação de senha). Derruba as sessões abertas.
     */
    public function updatePassword(Request $request, Prospector $prospector): Response
    {
        $data = $request->validate(
            ['password' => ['required', 'string', Password::min(8)]],
            ['password.min' => 'A senha precisa ter pelo menos 8 caracteres.'],
        );

        $user = $prospector->user ?? $prospector->user()->make(['phone_number' => $prospector->phone_number]);
        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();

        return response()->noContent();
    }

    private function forDetail(Prospector $prospector): Prospector
    {
        return $prospector->refresh()
            ->load(['city:id,name,state', 'leads:id,prospector_id,stage'])
            ->loadCount(['leads', 'attendedLeads']);
    }
}
