<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientRequest;
use App\Http\Resources\Admin\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Clientes da ótica (admin e atendente): lista com busca, cadastrar e editar. Também o "Novo cliente" do pedido ao
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
                $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->when($digits !== '', fn ($q) => $q->orWhere('phone_number', 'like', "%{$digits}%")));
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
        $client->update($request->validated());

        return new ClientResource($client->load('city:id,name,state'));
    }
}
