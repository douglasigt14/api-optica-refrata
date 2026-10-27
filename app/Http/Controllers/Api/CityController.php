<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    /**
     * Cidades que a ótica atende. Parceiro logado (com o token): as da ótica dele (formulário de indicação). Sem login: as
     * da ótica do convite (`?invite_code=`, formulário de cadastro); sem código e com uma ótica só na plataforma, as dela
     * (app antigo). Código que não existe → 404.
     */
    public function index(Request $request): JsonResponse
    {
        $partner = auth('api')->user()?->prospector;
        $company = $partner ? $partner->company : Company::findForInvite($request->query('invite_code'));
        abort_unless($company, 404, 'Código da ótica não encontrado.');

        return response()->json([
            'data' => City::servedBy($company)->orderBy('name')->get(['id', 'name', 'state']),
        ]);
    }

    /**
     * A ótica do código de convite (link de convite ou código digitado no cadastro): nome e cidades que atende.
     */
    public function company(string $code): JsonResponse
    {
        $company = filled($code) ? Company::findForInvite($code) : null;
        abort_unless($company, 404, 'Código da ótica não encontrado.');

        return response()->json(['data' => [
            'name' => $company->displayName(),
            'invite_code' => $company->invite_code,
            'cities' => City::servedBy($company)->orderBy('name')->get(['id', 'name', 'state']),
        ]]);
    }
}
