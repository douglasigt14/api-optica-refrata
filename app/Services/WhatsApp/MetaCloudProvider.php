<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppNumber;
use Illuminate\Validation\ValidationException;

/**
 * Lugar da **API oficial** (Meta / WhatsApp Cloud API), para quando a gerência aprovar. Vai entrar atrás da mesma porta
 * (`Provider`): conexão pelo token da Meta (sem QR Code), modelos aprovados para iniciar conversa, disparo em massa e
 * a API de chamadas (ligação pelo navegador sem mostrar o número).
 */
class MetaCloudProvider implements Provider
{
    public function __construct(private WhatsAppNumber $number) {}

    private function notYet(): never
    {
        throw ValidationException::withMessages(['whatsapp' => 'A API oficial do WhatsApp ainda não está ligada.']);
    }

    public function setUp(string $webhookUrl, string $webhookSecret): void
    {
        $this->notYet();
    }

    public function qrCode(): ?string
    {
        return null;
    }

    public function status(): string
    {
        return 'disconnected';
    }

    public function disconnect(): void {}

    public function remove(): void {}

    public function sendText(string $number, string $text): ?string
    {
        $this->notYet();
    }

    public function sendAudio(string $number, string $base64): ?string
    {
        $this->notYet();
    }
}
