<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp pela **Evolution API** v2 (não oficial; imita o WhatsApp Web, há risco de bloqueio do número). Endereço e chave
 * vêm do número (banco), nunca de `.env`. Rotas conferidas na 2.3.7: `instance/create`, `instance/connect/{i}`,
 * `instance/connectionState/{i}`, `instance/logout/{i}`, `instance/delete/{i}`, `message/sendText/{i}`,
 * `message/sendWhatsAppAudio/{i}`.
 */
class EvolutionProvider implements Provider
{
    public function __construct(private WhatsAppNumber $number) {}

    /** O cabeçalho do ngrok pula a página de aviso do túnel gratuito (quando a Evolution roda atrás dele). */
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->number->base_url, '/'))->withHeaders(['apikey' => $this->number->api_key, 'ngrok-skip-browser-warning' => '1'])->acceptJson()->timeout(30);
    }

    public function setUp(string $webhookUrl, string $webhookSecret): void
    {
        $this->call(fn () => $this->http()->post('/instance/create', [
            'instanceName' => $this->number->instance,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
            'webhook' => [
                'enabled' => true,
                'url' => $webhookUrl,
                'byEvents' => false,
                'base64' => true,
                'headers' => ['X-Webhook-Secret' => $webhookSecret],
                // Sem MESSAGES_UPDATE (12/11/2026): o aviso de enviada/entregue/lida vinha 3 vezes por mensagem e gastava o
                // limite de 500 conexões por hora do banco da Hostinger. Os tiques de entregue/lida ficam para o tempo real.
                'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
            ],
        ]));
    }

    public function qrCode(): ?string
    {
        $data = $this->call(fn () => $this->http()->get("/instance/connect/{$this->number->instance}"));

        return $data['base64'] ?? null;
    }

    public function status(): string
    {
        $data = $this->call(fn () => $this->http()->get("/instance/connectionState/{$this->number->instance}"));

        return match ($data['instance']['state'] ?? $data['state'] ?? null) {
            'open' => 'connected',
            'connecting' => 'connecting',
            default => 'disconnected',
        };
    }

    public function disconnect(): void
    {
        $this->call(fn () => $this->http()->delete("/instance/logout/{$this->number->instance}"), ignoreErrors: true);
    }

    public function remove(): void
    {
        $this->call(fn () => $this->http()->delete("/instance/delete/{$this->number->instance}"), ignoreErrors: true);
    }

    public function sendText(string $number, string $text): ?string
    {
        $data = $this->call(fn () => $this->http()->post("/message/sendText/{$this->number->instance}", ['number' => $number, 'text' => $text]));

        return $data['key']['id'] ?? null;
    }

    public function sendAudio(string $number, string $base64): ?string
    {
        $data = $this->call(fn () => $this->http()->timeout(90)->post("/message/sendWhatsAppAudio/{$this->number->instance}", ['number' => $number, 'audio' => $base64]));

        return $data['key']['id'] ?? null;
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    private function call(callable $request, bool $ignoreErrors = false): array
    {
        try {
            return $request()->throw()->json() ?? [];
        } catch (RequestException $e) {
            if ($ignoreErrors) {
                return [];
            }
            $message = $e->response->json('response.message.0') ?? $e->response->json('message') ?? $e->getMessage();
            throw ValidationException::withMessages(['whatsapp' => 'WhatsApp: '.(is_array($message) ? json_encode($message) : $message)]);
        } catch (ConnectionException) {
            if ($ignoreErrors) {
                return [];
            }
            throw ValidationException::withMessages(['whatsapp' => 'Servidor do WhatsApp fora do ar ('.$this->number->base_url.').']);
        }
    }
}
