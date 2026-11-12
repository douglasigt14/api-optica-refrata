<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientRequest;
use App\Http\Resources\Admin\ClientResource;
use App\Models\Client;
use App\Models\Sale;
use App\Support\PhonePrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Clientes da ótica (admin e atendente): lista com busca, cadastrar e editar; apagar só o admin. Também o "Novo cliente" do pedido ao
 * laboratório.
 */
class ClientController extends Controller
{
    /**
     * Lista paginada com busca (nome ou telefone) e cidade, em ordem alfabética.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $clients = Client::query()
            ->with('city:id,name,state')
            ->withCount('labOrders')
            ->when($filters['city_id'] ?? null, fn ($q, $id) => $q->where('city_id', $id))
            ->when(trim($filters['search'] ?? ''), function ($q, string $search) {
                $digits = preg_replace('/\D/', '', $search);
                // Buscar pelo telefone só o admin (sigilo, 12/11/2026); pelo CPF, todos.
                $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->when($digits !== '' && PhonePrivacy::canSee(), fn ($q) => $q->orWhere('phone_number', 'like', "%{$digits}%"))
                    ->when($digits !== '', fn ($q) => $q->orWhere('cpf', 'like', "{$digits}%")));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ClientResource::collection($clients);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client->load('city:id,name,state')->loadCount('labOrders'));
    }

    public function store(ClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return (new ClientResource($client->load('city:id,name,state')))->response()->setStatusCode(201);
    }

    public function update(ClientRequest $request, Client $client): ClientResource
    {
        $data = $request->validated();
        // Quem não vê o número só pode trocá-lo por outro: telefone vazio mantém o atual (não apaga sem ver).
        if (! PhonePrivacy::canSee($request) && empty($data['phone_number'])) {
            unset($data['phone_number']);
        }
        $client->update($data);

        return new ClientResource($client->load('city:id,name,state'));
    }

    /**
     * Apaga o cliente (só o admin, 09/11/2026). Só quem não tem pedido nem venda: esses ficam (são o histórico da ótica).
     */
    public function destroy(Client $client): Response
    {
        if ($client->labOrders()->exists()) {
            throw ValidationException::withMessages(['client' => 'Este cliente tem pedidos ao laboratório e não pode ser apagado.']);
        }
        if (Sale::where('client_id', $client->id)->exists()) {
            throw ValidationException::withMessages(['client' => 'Este cliente tem fichas e não pode ser apagado.']);
        }
        $client->delete();

        return response()->noContent();
    }
}
