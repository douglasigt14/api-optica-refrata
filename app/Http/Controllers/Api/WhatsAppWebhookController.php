<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\Inbox;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook do servidor do WhatsApp (CRM, 12/11/2026): mensagens novas, situação das enviadas e da conexão. Conferido
 * pelo segredo do número (cabeçalho `X-Webhook-Secret`, guardado no banco).
 */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, Inbox $inbox, int $number): Response
    {
        $whatsapp = WhatsAppNumber::withoutGlobalScopes()->with('company')->find($number);
        if (! $whatsapp || ! hash_equals($whatsapp->webhook_secret, (string) $request->header('X-Webhook-Secret'))) {
            abort(403);
        }
        app(CurrentCompany::class)->within($whatsapp->company, fn () => $inbox->handleWebhook($whatsapp, $request->all()));

        return response()->noContent();
    }
}
