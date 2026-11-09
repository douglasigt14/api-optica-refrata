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
use Illuminate\Support\Facades\DB;
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
            // Baixa feita no Financeiro: fica na conta de lá.
            $account = $payment->financial_account_id ?? $accounts[$payment->method] ?? null;
            if (! $account) {
                throw ValidationException::withMessages(['payments' => 'Escolha em Configurações a conta do Financeiro para '.SalePayment::METHODS[$payment->method].' (só o admin).']);
            }

            return [
                'id' => (string) $payment->id,
                // A baixa de lá que a ficha puxou (o Financeiro não a estorna como desconhecida).
                'financial_payment_id' => $payment->financial_payment_id,
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
        // Voltaram no estado da ficha: lá viraram baixas da ótica (com o id daqui).
        $sale->payments()->whereNotNull('financial_payment_id')->update(['financial_payment_id' => null]);
    }

    /**
     * Puxa para as fichas lançadas as baixas feitas no Financeiro (09/11/2026): cada baixa nova de lá vira pagamento da
     * ficha (`from_financial`); a que foi estornada lá antes de voltar sai daqui. Quitar não entrega: a ficha só mostra o
     * pago e o que falta. `strict` = falha vira exceção (antes de mudar a ficha); senão, a tela segue com o que tem.
     *
     * @param  iterable<Sale>  $sales
     * @return bool se alguma ficha mudou
     */
    public function pull(iterable $sales, bool $strict = false): bool
    {
        $sales = collect($sales)->filter(fn (Sale $sale) => $sale->launched_at && ! $sale->delivered_at && ! $sale->canceled_at)->keyBy('id');
        $company = $this->current->get();
        if ($sales->isEmpty() || ! $company || ! $this->connected($company)) {
            return false;
        }

        try {
            $remote = $this->call($company, fn (PendingRequest $http) => $http->get('/api/integrations/optica/sales/payments', ['ids' => $sales->keys()->implode(',')]))['data'] ?? [];
        } catch (RuntimeException|ValidationException $error) {
            if ($strict) {
                throw $error;
            }
            report($error);

            return false;
        }

        $changed = false;
        $byAccount = array_flip(array_map('intval', (array) ($company->sale_accounts ?? [])));
        foreach ($sales as $id => $sale) {
            $payments = collect($remote[(string) $id]['payments'] ?? []);
            $sale->loadMissing('payments');
            $pending = $sale->payments->whereNotNull('financial_payment_id');
            $gone = $pending->reject(fn (SalePayment $payment) => $payments->contains('id', $payment->financial_payment_id));
            $new = $payments->reject(fn (array $payment) => $pending->contains('financial_payment_id', (int) $payment['id']));
            if ($gone->isEmpty() && $new->isEmpty()) {
                continue;
            }
            DB::transaction(function () use ($sale, $gone, $new, $byAccount) {
                $gone->each->delete();
                foreach ($new as $payment) {
                    $sale->payments()->create([
                        'stage' => 'down',
                        'method' => $this->method($payment, $byAccount),
                        'amount' => $payment['amount'],
                        'installments' => null,
                        'paid_on' => $payment['paid_at'],
                        'from_financial' => true,
                        'financial_payment_id' => (int) $payment['id'],
                        'financial_account_id' => (int) $payment['account_id'],
                    ]);
                }
            });
            $sale->unsetRelation('payments');
            $changed = true;
        }

        return $changed;
    }

    /**
     * A forma da baixa de lá: pelo nome da forma de pagamento (PIX, Cartão…), senão pela conta escolhida para cada forma
     * aqui, senão Espécie.
     *
     * @param  array<string, mixed>  $payment
     * @param  array<int, string>  $byAccount
     */
    private function method(array $payment, array $byAccount): string
    {
        $name = mb_strtolower((string) ($payment['payment_method'] ?? ''));

        return match (true) {
            str_contains($name, 'pix') => 'pix',
            str_contains($name, 'cart') || str_contains($name, 'créd') || str_contains($name, 'déb') => 'card',
            str_contains($name, 'dinheiro') || str_contains($name, 'espécie') => 'cash',
            default => $byAccount[(int) ($payment['account_id'] ?? 0)] ?? 'cash',
        };
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
