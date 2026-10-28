<?php

namespace App\Services;

use App\Models\LabConnection;
use App\Models\LabOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A ótica falando com um laboratório do grupo (API de integração de lá, chave gravada pelo Console): catálogo do pedido,
 * mandar o pedido (vira OS), consultar e cancelar. Erro de validação do laboratório volta como erro do formulário
 * (`ValidationException`, com os mesmos campos); sem conexão ou recusa, `RuntimeException` com a mensagem em português.
 */
class Laboratory
{
    /**
     * @return array<string, mixed>
     */
    public function catalog(LabConnection $lab): array
    {
        return $this->call($lab, fn (PendingRequest $http) => $http->get('/api/integration/catalog'))['data'];
    }

    /**
     * Manda o pedido (com o anexo da receita, se houver) e grava o nº da OS e a situação. Repetir não duplica lá.
     */
    public function send(LabOrder $order): void
    {
        $order->loadMissing(['lab', 'client']);
        // Sem os vazios: o laboratório recusa campo que não cabe no pedido mesmo nulo (ex.: adição em visão simples).
        $data = self::withoutNulls([
            ...$order->payload,
            'external_id' => (string) $order->id,
            'optical_order_number' => (string) $order->id,
            'patient_reference' => $order->client->name,
        ]);

        $response = $this->call($order->lab, function (PendingRequest $http) use ($order, $data) {
            if (! $order->prescription_path) {
                return $http->post('/api/integration/service-orders', $data);
            }
            $file = $order->prescription_path;

            return $http->attach('prescription', Storage::disk('local')->get($file), basename($file))
                ->post('/api/integration/service-orders', self::multipart($data));
        });
        $order->update(['sent_at' => now(), 'send_error' => null]);
        $order->applyLabStatus($response['data']);
    }

    public function refresh(LabOrder $order): void
    {
        $order->applyLabStatus($this->call($order->lab, fn (PendingRequest $http) => $http->get("/api/integration/service-orders/{$order->id}"))['data']);
    }

    public function cancel(LabOrder $order, string $reason): void
    {
        $order->applyLabStatus($this->call($order->lab, fn (PendingRequest $http) => $http->post("/api/integration/service-orders/{$order->id}/cancel", ['reason' => $reason]))['data']);
    }

    /**
     * Tira os campos nulos, também dentro dos olhos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $data): array
    {
        return collect($data)
            ->reject(fn ($value) => $value === null)
            ->map(fn ($value) => is_array($value) ? self::withoutNulls($value) : $value)
            ->all();
    }

    /**
     * Campos do multipart (com o anexo): `eyes[0][sphere]`, verdadeiro/falso como 1/0, sem os vazios.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{name: string, contents: string}>
     */
    public static function multipart(array $data, string $prefix = ''): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                $fields = [...$fields, ...self::multipart($value, $name)];
            } elseif ($value !== null && $value !== '') {
                $fields[] = ['name' => $name, 'contents' => is_bool($value) ? ($value ? '1' : '0') : (string) $value];
            }
        }

        return $fields;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function call(LabConnection $lab, callable $request): array
    {
        if (! $lab->connected()) {
            throw new RuntimeException("{$lab->name}: a ligação com o laboratório não terminou. Peça à Refrata para ligar de novo no Console.");
        }
        $http = Http::baseUrl(rtrim($lab->url, '/'))->withToken($lab->token)->acceptJson()->timeout(20);

        try {
            return $request($http)->throw()->json() ?? [];
        } catch (ConnectionException) {
            throw new RuntimeException("{$lab->name}: sem conexão com o laboratório. Tente de novo.");
        } catch (RequestException $error) {
            $body = $error->response->json() ?? [];
            if ($error->response->status() === 422 && ! empty($body['errors'])) {
                throw ValidationException::withMessages(collect($body['errors'])->map(fn ($messages) => (array) $messages)->all());
            }
            if ($error->response->status() === 404) {
                throw new RuntimeException("{$lab->name}: o laboratório não encontrou este pedido.");
            }
            $message = $body['message'] ?? "erro {$error->response->status()}";
            throw new RuntimeException("{$lab->name} recusou: ".rtrim((string) $message, '.').'.');
        }
    }
}
