<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PhoneReveal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Ligar pelo WhatsApp" (12/11/2026, decisão do Douglas): a API não oficial não faz ligação de voz, então o botão abre o
 * app do WhatsApp na conversa da pessoa (lá se liga). O link leva o número, por isso ele só sai da API aqui, no clique, e
 * cada abertura fica registrada (`phone_reveals`). Nas listas e telas o número continua mascarado.
 */
class WhatsAppLinkController extends Controller
{
    public function client(Request $request, Client $client): JsonResponse
    {
        return $this->link($request, $client, $client->phone_number);
    }

    public function lead(Request $request, Lead $lead): JsonResponse
    {
        return $this->link($request, $lead, $lead->phone_number);
    }

    private function link(Request $request, Model $subject, ?string $phone): JsonResponse
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            throw ValidationException::withMessages(['phone_number' => 'Sem telefone cadastrado.']);
        }
        PhoneReveal::create(['user_id' => $request->user()->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()]);

        return response()->json(['data' => ['url' => 'https://wa.me/55'.$digits]]);
    }
}
