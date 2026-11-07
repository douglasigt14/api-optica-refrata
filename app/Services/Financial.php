<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\CurrentCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A ótica falando com o Financeiro (vendas da promissória, 07/11/2026): a ligação (endereço e chave) é gravada pelo
 * Console na empresa (`financial_api_url`, `financial_token`), nunca em `.env`. A venda lançada vira título a receber lá;
 * a cada mudança, manda o estado inteiro de novo (o Financeiro ajusta o título e as baixas).
 */
class Financial
{
    public function __construct(private CurrentCompany $current) {}

    public function connected(?Company $company = null): bool
    {
        $company ??= $this->current->require();

        return filled($company->financial_api_url) && filled($company->financial_token);
    }

    /**
     * Contas ativas da empresa lá (para escolher uma por forma de pagamento).
     *
     * @return list<array{id: int, name: string, kind: string}>
     */
    public function accounts(?Company $company = null): array
    {
        return $this->call($company ?? $this->current->require(), fn (PendingRequest $http) => $http->get('/api/integrations/optica/accounts'))['data'] ?? [];
    }

    /**
     * Manda o estado da venda (cria ou ajusta o título). Grava quando deu certo; falha vira exceção (quem chamou desfaz).
     */
    public function sync(Sale $sale): void
    {
        $company = $this->current->require();
        $sale->loadMissing(['client', 'orders', 'payments']);
        $accounts = (array) ($company->sale_accounts ?? []);
        $payments = $sale->payments->map(function (SalePayment $payment) use ($accounts) {
            $account = $accounts[$payment->method] ?? null;
            if (! $account) {
                throw ValidationException::withMessages(['payments' => 'Escolha em Configurações a conta do Financeiro para '.SalePayment::METHODS[$payment->method].' (só o admin).']);
            }

            return [
                'account_id' => (int) $account,
                'method' => $payment->method,
                'installments' => $payment->installments,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_on->toDateString(),
            ];
        })->values()->all();
        $count = $sale->activeOrders()->count();
        $issue = ($sale->launched_at ?? now())->toDateString();
        $due = $sale->expected_delivery_date->toDateString();

        $this->call($company, fn (PendingRequest $http) => $http->put("/api/integrations/optica/sales/{$sale->id}", [
            'description' => mb_substr("Venda nº {$sale->id} · {$sale->client->name} · {$count} ".($count === 1 ? 'óculos' : 'óculos'), 0, 255),
            'document_number' => "VD{$sale->id}",
            'issue_date' => $issue,
            'due_date' => $due < $issue ? $issue : $due,
            'amount' => number_format($sale->totalCents() / 100, 2, '.', ''),
            'customer' => [
                'name' => $sale->client->name,
                'document' => (string) $sale->client->cpf,
                'phone' => $sale->client->phone_number,
                'address' => $sale->client->address,
            ],
            'payments' => $payments,
        ]));
        $sale->forceFill(['financial_synced_at' => now(), 'financial_error' => null])->saveQuietly();
    }

    public function cancel(Sale $sale, string $reason): void
    {
        $this->call($this->current->require(), fn (PendingRequest $http) => $http->post("/api/integrations/optica/sales/{$sale->id}/cancel", ['reason' => $reason]));
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function call(Company $company, callable $request): array
    {
        if (! $this->connected($company)) {
            throw new RuntimeException('A ótica ainda não está ligada ao Financeiro. Peça à Refrata para ligar no Console.');
        }
        $http = Http::baseUrl(rtrim($company->financial_api_url, '/'))->withToken($company->financial_token)->acceptJson()->timeout(20);

        try {
            return $request($http)->throw()->json() ?? [];
        } catch (ConnectionException) {
            throw new RuntimeException('Sem conexão com o Financeiro. Tente de novo.');
        } catch (RequestException $error) {
            $body = $error->response->json() ?? [];
            if ($error->response->status() === 422 && ! empty($body['errors'])) {
                throw ValidationException::withMessages(['financial' => 'O Financeiro recusou: '.collect($body['errors'])->flatten()->first()]);
            }
            $message = $body['message'] ?? "erro {$error->response->status()}";
            throw new RuntimeException('O Financeiro recusou: '.rtrim((string) $message, '.').'.');
        }
    }
}
