<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Modelos de mensagem do CRM (12/11/2026): todos usam; o admin cria, edita e apaga. Variáveis: `{nome}`, `{cidade}`,
 * `{data_atendimento}`, `{otica}`.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = MessageTemplate::query()->when(! $request->boolean('all'), fn ($q) => $q->where('active', true))->orderBy('name')->get();

        return response()->json(['data' => $templates->map(fn (MessageTemplate $t) => $t->only(['id', 'name', 'body', 'active'])), 'variables' => MessageTemplate::VARIABLES]);
    }

    public function store(Request $request): JsonResponse
    {
        $template = MessageTemplate::create($this->validated($request));

        return response()->json(['data' => $template->only(['id', 'name', 'body', 'active'])], 201);
    }

    public function update(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        $messageTemplate->update($this->validated($request));

        return response()->json(['data' => $messageTemplate->only(['id', 'name', 'body', 'active'])]);
    }

    public function destroy(MessageTemplate $messageTemplate): Response
    {
        $messageTemplate->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(
            ['name' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:4000'], 'active' => ['sometimes', 'boolean']],
            ['name.required' => 'Dê um nome ao modelo.', 'body.required' => 'Escreva o texto do modelo.'],
        );
    }
}
