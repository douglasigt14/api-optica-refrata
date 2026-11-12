<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\Client;
use App\Models\CrmEntry;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Inbox;
use App\Support\CurrentCompany;
use App\Support\PhonePrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Conversas do WhatsApp no CRM (admin e atendente; 12/11/2026): caixa de entrada, conversa com o cliente ou a indicação,
 * enviar texto (livre ou modelo) e áudio, e tocar os áudios. **O número da pessoa nunca sai na resposta.**
 */
class WhatsAppChatController extends Controller
{
    public function __construct(private Inbox $inbox) {}

    /** Caixa de entrada: as conversas, as não lidas primeiro, depois a mais recente. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['unread' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);
        $conversations = WhatsAppConversation::query()->with(['client:id,name,phone_number,city_id', 'client.city:id,name', 'lead:id,name,phone_number,city_id,stage', 'lead.city:id,name', 'number:id,name'])
            ->whereNotNull('last_message_at')
            ->when($filters['unread'] ?? false, fn ($q) => $q->where('unread_count', '>', 0))
            ->when(trim($filters['search'] ?? ''), fn ($q, $s) => $q->where(fn ($q) => $q->where('push_name', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($q) => $q->where('name', 'like', "%{$s}%"))
                ->orWhereHas('lead', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->orderByRaw('unread_count > 0 DESC')->orderByDesc('last_message_at')->limit(100)->get();

        $crm = self::latestCrm($conversations->pluck('client_id')->filter()->unique()->all());

        return response()->json(['data' => $conversations->map(fn (WhatsAppConversation $c) => self::conversationData($c, $crm))->values()]);
    }

    /** Conversa (as últimas 100 mensagens); abrir marca como lida. */
    public function show(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->update(['unread_count' => 0]);

        return $this->conversationResponse($conversation);
    }

    /** Abre (ou cria) a conversa com um cliente ou uma indicação pelo número padrão da ótica. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['required_without:lead_id', 'nullable', 'integer'], 'lead_id' => ['required_without:client_id', 'nullable', 'integer']]);
        $person = ! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : Lead::findOrFail($data['lead_id']);
        $conversation = $this->inbox->conversationFor($person);
        $conversation->update(['unread_count' => 0]);

        return $this->conversationResponse($conversation);
    }

    /** Texto livre ou um modelo (com as variáveis do cliente e do atendimento preenchidas). */
    public function send(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required_without:template_id', 'nullable', 'string', 'max:4000'],
            'template_id' => ['nullable', 'integer', CurrentCompany::exists('message_templates')],
            'city_visit_id' => ['nullable', 'integer', CurrentCompany::exists('city_visits')],
        ], ['text.required_without' => 'Escreva a mensagem.']);
        $text = $data['text'] ?? null;
        if (! empty($data['template_id'])) {
            $visit = ! empty($data['city_visit_id']) ? CityVisit::with('city')->find($data['city_visit_id']) : null;
            $text = MessageTemplate::findOrFail($data['template_id'])->render($conversation->client?->name ?? $conversation->lead?->name ?? $conversation->push_name, $visit, app(CurrentCompany::class)->get());
        }
        $this->inbox->sendText($conversation, (string) $text, $request->user());

        return $this->conversationResponse($conversation->fresh());
    }

    /** Áudio gravado no navegador ou no app (vai como áudio de voz). */
    public function audio(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $request->validate(['audio' => ['required', 'file', 'max:16384']], ['audio.required' => 'Grave o áudio.']);
        $file = $request->file('audio');
        $this->inbox->sendAudio($conversation, $file->get(), $file->getMimeType() ?: 'audio/webm', $request->user());

        return $this->conversationResponse($conversation->fresh());
    }

    /** O arquivo do áudio ou da imagem da mensagem. */
    public function media(WhatsAppMessage $message): StreamedResponse
    {
        abort_unless($message->media_path && Storage::disk('local')->exists($message->media_path), 404);

        return Storage::disk('local')->response($message->media_path, null, ['Content-Type' => $message->media_mime ?: 'application/octet-stream']);
    }

    private function conversationResponse(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->load(['client:id,name,phone_number,city_id', 'client.city:id,name', 'lead:id,name,phone_number,city_id,stage', 'lead.city:id,name', 'number:id,name,status']);
        $crm = self::latestCrm(array_filter([$conversation->client_id]));
        $messages = $conversation->messages()->with('user:id,name')->latest('id')->limit(100)->get()->reverse()->values();

        return response()->json(['data' => [
            ...self::conversationData($conversation, $crm),
            'messages' => $messages->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'type' => $m->type,
                'body' => $m->body,
                'has_media' => (bool) $m->media_path,
                'status' => $m->status,
                'error' => $m->error,
                'user' => $m->user?->only(['id', 'name']),
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    /**
     * Etapa atual de cada cliente no CRM: a do atendimento mais recente em que ele foi trabalhado.
     *
     * @param  array<int, int>  $clientIds
     * @return array<int, CrmEntry>
     */
    private static function latestCrm(array $clientIds): array
    {
        if (! $clientIds) {
            return [];
        }

        return CrmEntry::query()->with('cityVisit.city:id,name')->whereIn('client_id', $clientIds)
            ->orderByDesc('updated_at')->orderByDesc('id')->get()->unique('client_id')->keyBy('client_id')->all();
    }

    /**
     * @param  array<int, CrmEntry>  $crm
     * @return array<string, mixed>
     */
    private static function conversationData(WhatsAppConversation $c, array $crm = []): array
    {
        $person = $c->client ?? $c->lead;
        $entry = $c->client_id ? ($crm[$c->client_id] ?? null) : null;

        return [
            'id' => $c->id,
            'name' => $person?->name ?? $c->push_name ?? 'Contato sem cadastro',
            'push_name' => $c->push_name,
            'client_id' => $c->client_id,
            'lead_id' => $c->lead_id,
            // Sigilo: o mascarado para todos; o número só para o admin, e só de quem está cadastrado.
            'phone_masked' => $person ? PhonePrivacy::mask($person->phone_number) : null,
            // Cidade da pessoa e a situação atual: no CRM (cliente) ou a etapa da indicação (12/11/2026).
            'city' => $person?->city?->name,
            'crm' => $c->client_id ? [
                'stage' => $entry?->stage->value ?? 'to_contact',
                'stage_label' => $entry?->stage->label() ?? 'A contatar',
                'visit_id' => $entry?->city_visit_id,
                'visit' => $entry?->cityVisit ? trim(($entry->cityVisit->city?->name ?? '').' · '.$entry->cityVisit->visit_date->format('d/m')) : null,
            ] : null,
            'lead_stage_label' => $c->lead && ! $c->client_id ? $c->lead->stage?->label() : null,
            'number' => $c->number?->only(['id', 'name']),
            'unread_count' => $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message_preview' => $c->last_message_preview,
        ];
    }
}
