<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Group;
use App\Models\LabConnection;
use App\Services\Laboratory;
use App\Support\CurrentCompany;
use App\Support\DefaultLeadStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * O que o Console da Refrata faz na ótica (token da plataforma): criar/atualizar a ótica (e o grupo dela). A que já
 * existia aqui antes do Console (a Otica Exclusive, ainda sem ID) é ligada uma vez pelo CNPJ, e o grupo dela junto — não
 * duplica. Depois, só pelo ID do Console (`console_id`). Nunca pelo nome.
 * Ótica nova ganha o código de convite (pelo nome) e as situações padrão das indicações.
 */
class PlatformController extends Controller
{
    /**
     * Cria ou atualiza a ótica `consoleId`. `active` = a empresa está ativa e usa o módulo Ótica.
     */
    public function upsertCompany(Request $request, string $consoleId): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'array'],
            'group.console_id' => ['required', 'string', 'size:26'],
            'group.name' => ['required', 'string', 'max:255'],
            'group.blocked' => ['required', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'document' => ['required', 'digits:14'],
            'active' => ['required', 'boolean'],
        ]);

        [$company, $created] = DB::transaction(function () use ($data, $consoleId) {
            // Liga só por identidade: o código do Console ou, para a que já existia aqui, o CNPJ. Nunca pelo nome.
            $company = Company::where('console_id', $consoleId)->first()
                ?? Company::whereNull('console_id')->where('document', $data['document'])->first();
            $group = $this->group($data['group'], $company);
            $created = ! $company;
            abort_if($company && $company->group_id !== $group->id, 422, 'A empresa já é de outro grupo.');
            $clash = Company::where('document', $data['document'])->when($company, fn ($q) => $q->whereKeyNot($company->id))->exists();
            abort_if($clash, 422, 'Este CNPJ já é de outra ótica aqui.');

            $company ??= new Company;
            $company->fill([
                'group_id' => $group->id,
                'console_id' => $consoleId,
                'name' => $data['name'],
                'trade_name' => $data['trade_name'] ?? null,
                'document' => $data['document'],
                'active' => $data['active'],
            ]);
            $company->invite_code ??= Company::inviteCodeFor($data['trade_name'] ?? $data['name']);
            $company->save();
            if ($created) {
                DefaultLeadStatuses::createFor($company);
            }

            return [$company, $created];
        });

        return response()->json(['data' => [
            'id' => $company->id,
            'console_id' => $company->console_id,
            'group_id' => $company->group_id,
            'active' => $company->active,
            'invite_code' => $company->invite_code,
            'created' => $created,
        ]], $created ? 201 : 200);
    }

    /**
     * Liga um laboratório do grupo a esta ótica (passo 3; o Console faz as duas pontas): cria ou atualiza a ligação pelo ID
     * da empresa laboratório e gera a chave com que o laboratório avisa a situação das OS (a anterior deixa de valer).
     * Devolve a chave uma vez só; o Console grava no laboratório.
     */
    public function labConnection(Request $request, string $consoleId, string $labConsoleId): JsonResponse
    {
        $company = Company::where('console_id', $consoleId)->firstOrFail();
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];

        return app(CurrentCompany::class)->within($company, function () use ($labConsoleId, $name) {
            $token = 'roi_'.Str::random(60);
            LabConnection::updateOrCreate(['lab_console_id' => $labConsoleId], ['name' => $name, 'inbound_token_hash' => LabConnection::hash($token)]);

            return response()->json(['data' => ['inbound_token' => $token]]);
        });
    }

    /**
     * Grava o endereço do laboratório e a chave que a ótica usa lá (gerada pelo laboratório) e testa pelo catálogo.
     */
    public function labToken(Request $request, Laboratory $laboratory, string $consoleId, string $labConsoleId): JsonResponse
    {
        $company = Company::where('console_id', $consoleId)->firstOrFail();
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:255'],
            'token' => ['required', 'string', 'min:20', 'max:200'],
        ]);

        return app(CurrentCompany::class)->within($company, function () use ($data, $labConsoleId, $laboratory) {
            $lab = LabConnection::where('lab_console_id', $labConsoleId)->firstOrFail();
            $lab->update(['url' => rtrim($data['url'], '/'), 'token' => $data['token'], 'connected_at' => now()]);
            try {
                $catalog = $laboratory->catalog($lab);
            } catch (RuntimeException $error) {
                throw ValidationException::withMessages(['connection' => $error->getMessage()]);
            }

            return response()->json(['data' => ['connected' => true, 'treatments' => count($catalog['treatments'] ?? [])]]);
        });
    }

    /**
     * O grupo pelo código do Console; senão, o grupo ainda sem ligação da empresa que já existia aqui (achada pelo CNPJ),
     * que passa a ser ligado. Nunca pelo nome: nome é descrição e muda.
     *
     * @param  array{console_id: string, name: string, blocked: bool}  $data
     */
    private function group(array $data, ?Company $company): Group
    {
        $existing = $company?->group;
        $group = Group::where('console_id', $data['console_id'])->first()
            ?? ($existing && $existing->console_id === null ? $existing : null)
            ?? new Group;
        $group->fill([
            'console_id' => $data['console_id'],
            'name' => $data['name'],
            'blocked_at' => $data['blocked'] ? ($group->blocked_at ?? now()) : null,
        ])->save();

        return $group;
    }
}
