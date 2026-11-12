<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Conversas do WhatsApp do CRM (12/11/2026): guarda o que chega pelo webhook, liga a conversa ao cliente (ou à
 * indicação) pelo número e envia texto e áudio. O número da pessoa nunca sai daqui para as telas.
 */
class Inbox
{
    /** Conversa com um cliente ou uma indicação pelo número padrão da ótica (cria se não houver). */
    public function conversationFor(Client|Lead $person): WhatsAppConversation
    {
        $number = WhatsAppNumber::default() ?? throw ValidationException::withMessages(['whatsapp' => 'Nenhum WhatsApp ligado. O admin conecta em Configurações ▸ WhatsApp.']);
        $digits = preg_replace('/\D/', '', (string) $person->phone_number);
        if ($digits === '') {
            throw ValidationException::withMessages(['whatsapp' => 'Sem telefone cadastrado.']);
        }
        $jid = '55'.$digits.'@s.whatsapp.net';
        $conversation = WhatsAppConversation::firstOrNew(['whatsapp_number_id' => $number->id, 'remote_hash' => WhatsAppConversation::hashFor($number->id, $jid)]);
        $conversation->remote_jid = $jid;
        $conversation->{$person instanceof Client ? 'client_id' : 'lead_id'} ??= $person->id;
        $conversation->save();

        return $conversation;
    }

    public function sendText(WhatsAppConversation $conversation, string $text, ?User $user): WhatsAppMessage
    {
        $message = $this->outgoing($conversation, $user, ['type' => 'text', 'body' => $text]);
        try {
            $message->update(['external_id' => $conversation->number->provider()->sendText($conversation->remoteDigits(), $text), 'status' => 'sent']);
        } catch (ValidationException $e) {
            $message->update(['status' => 'failed', 'error' => Str::limit(collect($e->errors())->flatten()->first(), 250)]);
            throw $e;
        }

        return $message;
    }

    /** Áudio gravado pela atendente (o arquivo fica guardado para tocar na conversa). */
    public function sendAudio(WhatsAppConversation $conversation, string $contents, string $mime, ?User $user): WhatsAppMessage
    {
        $path = 'whatsapp/'.$conversation->company_id.'/'.Str::uuid().'.'.$this->extension($mime);
        Storage::disk('local')->put($path, $contents);
        $message = $this->outgoing($conversation, $user, ['type' => 'audio', 'media_path' => $path, 'media_mime' => $mime]);
        try {
            $message->update(['external_id' => $conversation->number->provider()->sendAudio($conversation->remoteDigits(), base64_encode($contents)), 'status' => 'sent']);
        } catch (ValidationException $e) {
            $message->update(['status' => 'failed', 'error' => Str::limit(collect($e->errors())->flatten()->first(), 250)]);
            throw $e;
        }

        return $message;
    }

    /**
     * Evento do webhook da Evolution (`messages.upsert`, `messages.update`, `connection.update`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(WhatsAppNumber $number, array $payload): void
    {
        $event = str_replace('_', '.', strtolower((string) ($payload['event'] ?? '')));
        $data = $payload['data'] ?? [];
        match ($event) {
            'messages.upsert' => $this->incoming($number, $data),
            'messages.update' => $this->statusUpdate($data),
            'connection.update' => $this->connection($number, $data),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function incoming(WhatsAppNumber $number, array $data): void
    {
        $key = $data['key'] ?? [];
        $jid = (string) (str_contains((string) ($key['remoteJid'] ?? ''), '@lid') && ! empty($key['remoteJidAlt']) ? $key['remoteJidAlt'] : ($key['remoteJid'] ?? ''));
        // Grupos, status e mensagens sem remetente ficam de fora.
        if ($jid === '' || str_ends_with($jid, '@g.us') || str_starts_with($jid, 'status@')) {
            return;
        }
        $externalId = $key['id'] ?? null;
        if ($externalId && WhatsAppMessage::query()->where('external_id', $externalId)->exists()) {
            return;
        }
        $fromMe = (bool) ($key['fromMe'] ?? false);
        $content = $data['message'] ?? [];
        [$type, $body] = match (true) {
            isset($content['conversation']) => ['text', $content['conversation']],
            isset($content['extendedTextMessage']['text']) => ['text', $content['extendedTextMessage']['text']],
            isset($content['audioMessage']) => ['audio', null],
            isset($content['imageMessage']) => ['image', $content['imageMessage']['caption'] ?? null],
            default => ['other', null],
        };
        $mime = $content['audioMessage']['mimetype'] ?? $content['imageMessage']['mimetype'] ?? null;
        $path = null;
        if (in_array($type, ['audio', 'image'], true) && ! empty($content['base64'])) {
            $path = 'whatsapp/'.$number->company_id.'/'.Str::uuid().'.'.$this->extension((string) $mime);
            Storage::disk('local')->put($path, base64_decode($content['base64']));
        }

        $conversation = WhatsAppConversation::firstOrNew(['whatsapp_number_id' => $number->id, 'remote_hash' => WhatsAppConversation::hashFor($number->id, $jid)]);
        $conversation->remote_jid = $jid;
        if (! $conversation->client_id && ! $conversation->lead_id) {
            [$conversation->client_id, $conversation->lead_id] = $this->match($jid);
        }
        if (! $fromMe && ! empty($data['pushName'])) {
            $conversation->push_name = Str::limit($data['pushName'], 120, '');
        }
        $conversation->save();

        $message = new WhatsAppMessage([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => $fromMe ? 'out' : 'in', 'type' => $type, 'body' => $body,
            'media_path' => $path, 'media_mime' => $mime, 'external_id' => $externalId, 'status' => $fromMe ? 'sent' : 'received',
        ]);
        $message->company_id = $number->company_id;
        $message->save();
        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => $this->preview($type, $body),
            'unread_count' => $fromMe ? $conversation->unread_count : $conversation->unread_count + 1,
        ])->save();
    }

    /** @param  array<string, mixed>  $data */
    private function statusUpdate(array $data): void
    {
        $id = $data['keyId'] ?? $data['key']['id'] ?? null;
        $status = match ($data['status'] ?? null) {
            'DELIVERY_ACK' => 'delivered',
            'READ', 'PLAYED' => 'read',
            'SERVER_ACK' => 'sent',
            default => null,
        };
        if ($id && $status) {
            WhatsAppMessage::withoutGlobalScopes()->where('external_id', $id)->where('direction', 'out')->update(['status' => $status]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function connection(WhatsAppNumber $number, array $data): void
    {
        $state = $data['state'] ?? null;
        $number->forceFill([
            'status' => match ($state) {
                'open' => 'connected',
                'connecting' => 'connecting',
                default => 'disconnected',
            },
            'connected_at' => $state === 'open' ? now() : $number->connected_at,
            'own_phone' => isset($data['wuid']) ? preg_replace('/\D/', '', explode('@', (string) $data['wuid'])[0]) : $number->own_phone,
        ])->save();
    }

    /**
     * Cliente (ou indicação) da ótica com esse número. O WhatsApp às vezes manda o celular sem o 9: tenta com e sem.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function match(string $jid): array
    {
        $digits = preg_replace('/\D/', '', explode('@', $jid)[0]);
        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        $candidates = [$digits];
        if (strlen($digits) === 10) {
            $candidates[] = substr($digits, 0, 2).'9'.substr($digits, 2);
        } elseif (strlen($digits) === 11 && $digits[2] === '9') {
            $candidates[] = substr($digits, 0, 2).substr($digits, 3);
        }
        $client = Client::query()->whereIn('phone_number', $candidates)->orderByDesc('id')->value('id');
        $lead = $client ? null : Lead::query()->whereIn('phone_number', $candidates)->orderByDesc('id')->value('id');

        return [$client, $lead];
    }

    /** @param  array<string, mixed>  $attributes */
    private function outgoing(WhatsAppConversation $conversation, ?User $user, array $attributes): WhatsAppMessage
    {
        $message = $conversation->messages()->create(['direction' => 'out', 'user_id' => $user?->id, 'status' => 'sending', ...$attributes]);
        $conversation->forceFill(['last_message_at' => now(), 'last_message_preview' => $this->preview($attributes['type'], $attributes['body'] ?? null)])->save();

        return $message;
    }

    private function preview(string $type, ?string $body): string
    {
        return match ($type) {
            'audio' => '🎤 Áudio',
            'image' => '📷 Imagem'.($body ? ': '.Str::limit($body, 120) : ''),
            'other' => 'Mensagem',
            default => Str::limit((string) $body, 150),
        };
    }

    private function extension(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a'), str_contains($mime, 'aac') => 'm4a',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            default => 'bin',
        };
    }
}
