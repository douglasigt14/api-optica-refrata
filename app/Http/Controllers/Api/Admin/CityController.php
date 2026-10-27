<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityVisit;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Cidades que a ótica atende: aparecem no cadastro e nas indicações dos parceiros e nos atendimentos. O admin escolhe
 * entre todos os municípios do IBGE (tela Cidades atendidas).
 */
class CityController extends Controller
{
    public function __construct(private CurrentCompany $current) {}

    /**
     * Cidades atendidas (para filtros, para o cadastro de atendimentos e para a tela Cidades atendidas).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => City::servedBy()->orderBy('name')->get(['id', 'name', 'state']),
        ]);
    }

    /**
     * Busca entre todos os municípios (nome, com ou sem UF: "iguatu", "iguatu ce"), com `served` = a ótica já atende.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100'], 'state' => ['nullable', 'string', 'size:2']]);
        $served = City::servedBy()->pluck('id')->flip();
        $cities = City::query()
            ->where('name', 'like', '%'.trim($data['search']).'%')
            ->when($data['state'] ?? null, fn ($q, string $state) => $q->where('state', mb_strtoupper($state)))
            ->orderBy('name')->orderBy('state')->limit(30)->get(['id', 'name', 'state']);

        return response()->json([
            'data' => $cities->map(fn (City $city) => [...$city->only(['id', 'name', 'state']), 'served' => $served->has($city->id)]),
        ]);
    }

    public function attach(City $city): JsonResponse
    {
        $this->current->require()->cities()->syncWithoutDetaching([$city->id]);

        return response()->json(['data' => [...$city->only(['id', 'name', 'state']), 'served' => true]]);
    }

    /**
     * Para de atender a cidade. Com atendimento aberto lá, recusa (cancele ou conclua antes). Parceiros e indicações da
     * cidade continuam.
     */
    public function detach(City $city): Response
    {
        if (CityVisit::query()->open()->where('city_id', $city->id)->exists()) {
            throw ValidationException::withMessages(['city' => "Há atendimento aberto em {$city->name}: conclua ou cancele antes de tirar a cidade."]);
        }
        $this->current->require()->cities()->detach($city->id);

        return response()->noContent();
    }
}
