<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\WhatsAppBroadcast;
use App\Models\WhatsAppNumber;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Disparo em massa (CRM, fase 5, 12/11/2026; admin): **pré-pronto**. Monta o disparo de um modelo para os clientes de um
 * atendimento, em lotes, com intervalo e agendamento, mas **só aceita número da API oficial** (decisão do Douglas: com a
 * não oficial o risco de bloqueio é grande). O envio dos lotes entra junto com a API oficial.
 */
class WhatsAppBroadcastController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => WhatsAppBroadcast::query()->latest()->limit(50)->get(),
            'enabled' => WhatsAppNumber::query()->where('provider', 'meta_cloud')->exists(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_number_id' => ['required', 'integer', CurrentCompany::exists('whatsapp_numbers')],
            'message_template_id' => ['required', 'integer', CurrentCompany::exists('message_templates')],
            'city_visit_id' => ['required', 'integer', CurrentCompany::exists('city_visits')],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'interval_seconds' => ['nullable', 'integer', 'min:5', 'max:3600'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        if (! WhatsAppNumber::findOrFail($data['whatsapp_number_id'])->allowsBroadcast()) {
            throw ValidationException::withMessages(['whatsapp_number_id' => 'O disparo em massa só funciona com a API oficial do WhatsApp.']);
        }
        $visit = CityVisit::findOrFail($data['city_visit_id']);
        $broadcast = WhatsAppBroadcast::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => 'scheduled',
            'total' => Client::query()->where('city_id', $visit->city_id)->whereNotNull('phone_number')->count(),
        ]);

        return response()->json(['data' => $broadcast], 201);
    }
}
