<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Prospector;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

    /**
     * Cadastra como cliente ou parceiro (13/11/2026) a pessoa da conversa ou, num grupo, quem mandou a mensagem escolhida.
     * O telefone vem do WhatsApp, no servidor (sem o 55; celular que chega sem o 9 ganha o 9). Cliente que já existe com
     * esse número é reaproveitado; a conversa direta fica ligada ao cadastro.
     *
     * @param  'client'|'partner'  $kind
     * @param  array<string, mixed>  $data
     */
    public function register(WhatsAppConversation $conversation, ?WhatsAppMessage $from, string $kind, array $data): Client|Prospector
    {
        if ($conversation->is_group) {
            $jid = $from?->sender_jid ?? throw ValidationException::withMessages(['person' => 'Escolha a mensagem de quem vai ser cadastrado.']);
        } else {
            if ($conversation->client_id || $conversation->prospector_id) {
                throw ValidationException::withMessages(['person' => 'Esta conversa já é de alguém cadastrado.']);
            }
            $jid = $conversation->remote_jid;
        }
        $phone = $this->localPhone($jid) ?? throw ValidationException::withMessages(['person' => 'O WhatsApp não informou o número desta pessoa; cadastre pela tela de Clientes ou Parceiros.']);

        $person = $kind === 'client' ? $this->registerClient($jid, $phone, $data) : $this->registerPartner($jid, $phone, $data);
        if (! $conversation->is_group) {
            $conversation->forceFill([$kind === 'client' ? 'client_id' : 'prospector_id' => $person->id])->save();
        }

        return $person;
    }

    /** @param  array<string, mixed>  $data */
    private function registerClient(string $jid, string $phone, array $data): Client
    {
        $existing = Client::query()->whereIn('phone_number', $this->candidates($jid))->orderByDesc('id')->first();

        return $existing ?? Client::create([...$data, 'phone_number' => $phone]);
    }

    /** O telefone é o login do parceiro no app (como no cadastro pela tela Parceiros). @param  array<string, mixed>  $data */
    private function registerPartner(string $jid, string $phone, array $data): Prospector
    {
        if (Prospector::query()->whereIn('phone_number', $this->candidates($jid))->exists()) {
            throw ValidationException::withMessages(['person' => 'Esta pessoa já é parceira.']);
        }
        if (User::query()->whereIn('phone_number', $this->candidates($jid))->exists()) {
            throw ValidationException::withMessages(['person' => 'Este número já é o login de outra pessoa no sistema.']);
        }

        return DB::transaction(function () use ($data, $phone) {
            $prospector = Prospector::create([...Arr::except($data, ['password']), 'phone_number' => $phone]);
            $prospector->user()->create(['phone_number' => $phone, 'password' => $data['password']]);

            return $prospector;
        });
    }

    /**
     * Quem escreveu nos grupos já é cliente ou parceiro? Por mensagem: `client`, `partner` ou null.
     *
     * @param  iterable<WhatsAppMessage>  $messages
     * @return array<int, ?string>
     */
    public function registeredSenders(iterable $messages): array
    {
        $phones = [];
        foreach ($messages as $m) {
            if ($m->sender_jid) {
                $phones[$m->id] = $this->candidates($m->sender_jid);
            }
        }
        if (! $phones) {
            return [];
        }
        $all = array_values(array_unique(array_merge(...array_values($phones))));
        $clients = Client::query()->whereIn('phone_number', $all)->pluck('phone_number')->flip();
        $partners = Prospector::query()->whereIn('phone_number', $all)->pluck('phone_number')->flip();

        return array_map(fn (array $candidates) => match (true) {
            collect($candidates)->contains(fn ($p) => $clients->has($p)) => 'client',
            collect($candidates)->contains(fn ($p) => $partners->has($p)) => 'partner',
            default => null,
        }, $phones);
    }

    public function sendText(WhatsAppConversation $conversation, string $text, ?User $user): WhatsAppMessage
    {
        $message = $this->outgoing($conversation, $user, ['type' => 'text', 'body' => $text]);
        try {
            $message->update(['external_id' => $conversation->number->provider()->sendText($conversation->recipient(), $text), 'status' => 'sent']);
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
            $message->update(['external_id' => $conversation->number->provider()->sendAudio($conversation->recipient(), base64_encode($contents)), 'status' => 'sent']);
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
        // Status e mensagens sem remetente ficam de fora. Grupos entram (13/11/2026): a conversa é o grupo, com o nome dele;
        // cada mensagem guarda o nome do WhatsApp de quem escreveu e o identificador dele criptografado (para cadastrar).
        if ($jid === '' || str_starts_with($jid, 'status@') || str_ends_with($jid, '@broadcast') || str_ends_with($jid, '@newsletter')) {
            return;
        }
        $isGroup = str_ends_with($jid, '@g.us');
        $participant = (string) (str_contains((string) ($key['participant'] ?? ''), '@lid') && ! empty($key['participantAlt']) ? $key['participantAlt'] : ($key['participant'] ?? ''));
        $pushName = $this->safeName($data['pushName'] ?? null);
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
        if ($isGroup) {
            $conversation->is_group = true;
            // O nome do grupo é buscado uma vez (na primeira mensagem); depois fica guardado.
            $conversation->push_name ??= Str::limit($number->provider()->groupName($jid) ?? 'Grupo', 120, '');
        } else {
            if (! $conversation->client_id && ! $conversation->lead_id && ! $conversation->prospector_id) {
                [$conversation->client_id, $conversation->lead_id, $conversation->prospector_id] = $this->match($jid);
            }
            if (! $fromMe && $pushName) {
                $conversation->push_name = $pushName;
            }
        }
        $conversation->save();

        $message = new WhatsAppMessage([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => $fromMe ? 'out' : 'in', 'type' => $type, 'body' => $body,
            'sender_name' => $isGroup && ! $fromMe ? $pushName : null,
            'sender_jid' => $isGroup && ! $fromMe && $participant !== '' ? $participant : null,
            'media_path' => $path, 'media_mime' => $mime, 'external_id' => $externalId, 'status' => $fromMe ? 'sent' : 'received',
        ]);
        $message->company_id = $number->company_id;
        $message->save();
        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => ($isGroup && ! $fromMe && $pushName ? Str::limit($pushName, 40, '').': ' : '').$this->preview($type, $body),
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
     * Cliente, indicação ou parceiro da ótica com esse número (nessa ordem; só um vem preenchido).
     *
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function match(string $jid): array
    {
        $candidates = $this->candidates($jid);
        $client = Client::query()->whereIn('phone_number', $candidates)->orderByDesc('id')->value('id');
        $lead = $client ? null : Lead::query()->whereIn('phone_number', $candidates)->orderByDesc('id')->value('id');
        $partner = $client || $lead ? null : Prospector::query()->whereIn('phone_number', $candidates)->value('id');

        return [$client, $lead, $partner];
    }

    /**
     * O número como pode estar no cadastro: o WhatsApp às vezes manda o celular sem o 9, então tenta com e sem.
     *
     * @return list<string>
     */
    private function candidates(string $jid): array
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

        return $candidates;
    }

    /** "558899466956@s.whatsapp.net" → "88999466956"; o que não parecer telefone do Brasil fica sem número. */
    private function localPhone(string $jid): ?string
    {
        $digits = preg_replace('/\D/', '', explode('@', $jid)[0]);
        if (! str_ends_with($jid, '@s.whatsapp.net') || ! str_starts_with($digits, '55') || ! in_array(strlen($digits), [12, 13], true)) {
            return null;
        }
        $digits = substr($digits, 2);

        return strlen($digits) === 10 && $digits[2] >= '6' ? substr($digits, 0, 2).'9'.substr($digits, 2) : $digits;
    }

    /** Nome do WhatsApp. Sem nome, a Evolution manda o número no lugar: esse não é guardado (sigilo). */
    private function safeName(mixed $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' || preg_match_all('/\d/', $name) >= 6 ? null : Str::limit($name, 120, '');
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
