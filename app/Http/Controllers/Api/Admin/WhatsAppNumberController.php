<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configurações ▸ WhatsApp (admin; CRM, 12/11/2026): os números da ótica. Cadastrar cria a conexão no servidor da
 * Evolution (endereço e chave guardados no banco; nunca em `.env`) com o webhook das mensagens; conectar é pelo QR Code.
 */
class WhatsAppNumberController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => WhatsAppNumber::query()->orderByDesc('is_default')->orderBy('id')->get()->map(fn (WhatsAppNumber $n) => self::data($n))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Dê um nome ao número (ex.: Atendimento).', 'base_url.required' => 'Informe o endereço do servidor do WhatsApp.', 'api_key.required' => 'Informe a chave do servidor.']);

        $company = app(CurrentCompany::class)->require();
        $number = new WhatsAppNumber([
            ...$data,
            'provider' => 'evolution',
            'instance' => 'refrata-'.$company->id.'-'.Str::lower(Str::random(8)),
            'webhook_secret' => Str::random(48),
            'is_default' => ($data['is_default'] ?? false) || ! WhatsAppNumber::query()->exists(),
        ]);
        DB::transaction(function () use ($number) {
            if ($number->is_default) {
                WhatsAppNumber::query()->update(['is_default' => false]);
            }
            $number->save();
        });
        // Cria a conexão no servidor com o webhook apontando para esta API (a do endereço que o painel usa). Se o servidor
        // recusar (endereço ou chave errados), o número não fica.
        try {
            $number->provider()->setUp($request->root().'/api/integration/whatsapp/'.$number->id, $number->webhook_secret);
        } catch (\Throwable $e) {
            $number->delete();
            throw $e;
        }

        return response()->json(['data' => self::data($number)], 201);
    }

    public function update(Request $request, WhatsAppNumber $whatsappNumber): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:60'], 'is_default' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($whatsappNumber, $data) {
            if ($data['is_default'] ?? false) {
                WhatsAppNumber::query()->whereKeyNot($whatsappNumber->id)->update(['is_default' => false]);
            }
            $whatsappNumber->update($data);
        });

        return response()->json(['data' => self::data($whatsappNumber)]);
    }

    /** QR Code para conectar (e a situação de agora). */
    public function qr(WhatsAppNumber $whatsappNumber): JsonResponse
    {
        $status = $whatsappNumber->provider()->status();
        $whatsappNumber->update(['status' => $status, 'connected_at' => $status === 'connected' ? ($whatsappNumber->connected_at ?? now()) : $whatsappNumber->connected_at]);

        return response()->json(['data' => [...self::data($whatsappNumber), 'qr_code' => $status === 'connected' ? null : $whatsappNumber->provider()->qrCode()]]);
    }

    public function disconnect(WhatsAppNumber $whatsappNumber): JsonResponse
    {
        $whatsappNumber->provider()->disconnect();
        $whatsappNumber->update(['status' => 'disconnected']);

        return response()->json(['data' => self::data($whatsappNumber)]);
    }

    public function destroy(WhatsAppNumber $whatsappNumber): Response
    {
        $whatsappNumber->provider()->remove();
        $whatsappNumber->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    public static function data(WhatsAppNumber $n): array
    {
        return [
            'id' => $n->id,
            'name' => $n->name,
            'is_default' => $n->is_default,
            'provider' => $n->provider,
            'provider_label' => $n->provider === 'meta_cloud' ? 'API oficial' : 'API não oficial (Evolution)',
            'base_url' => $n->base_url,
            'status' => $n->status,
            'own_phone' => $n->own_phone,
            'connected_at' => $n->connected_at?->toIso8601String(),
            'allows_broadcast' => $n->allowsBroadcast(),
        ];
    }
}
