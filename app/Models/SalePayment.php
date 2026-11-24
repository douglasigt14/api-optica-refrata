<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento de uma venda: `stage` = `down` (entrada) ou `delivery` (na entrega); `method` = `cash` (espécie), `pix` ou
 * `card` (com `installments`, as vezes no cartão).
 *
 * Baixa feita no Financeiro (09/11/2026): entra aqui com `from_financial` (a ficha puxa ao abrir), na conta de lá
 * (`financial_account_id`). Até voltar no estado da ficha, guarda o id de lá (`financial_payment_id`) e não se apaga por
 * aqui (estorna-se lá).
 */
#[Fillable(['sale_id', 'stage', 'method', 'amount', 'installments', 'paid_on', 'from_financial', 'financial_payment_id', 'financial_account_id', 'user_id'])]
class SalePayment extends Model
{
    use BelongsToCompany;

    public const METHODS = ['cash' => 'Espécie', 'pix' => 'PIX', 'card' => 'Cartão'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'installments' => 'integer', 'from_financial' => 'boolean', 'financial_payment_id' => 'integer', 'financial_account_id' => 'integer'];
    }

    /**
     * O tipo na lista da ficha (09/11/2026): `financial` (baixa feita no Financeiro), `delivery` (na entrega), `down`
     * (entrada, paga ao lançar) ou `extra` (avulso, depois de lançar). Entrada e avulso têm o mesmo `stage`; o que separa
     * é ter sido gravado junto com o lançamento.
     *
     * Entrada apagada (24/11/2026), por exemplo por erro de digitação: se nenhum pagamento do lançamento sobrou, a entrada
     * passa a ser o primeiro pagamento feito depois (com os que forem gravados junto com ele, até 5 segundos depois).
     */
    public function kind(Sale $sale): string
    {
        return match (true) {
            $this->from_financial => 'financial',
            $this->stage === 'delivery' => 'delivery',
            default => ($at = self::downPaymentTime($sale)) && $this->created_at && $this->created_at->lte($at->copy()->addSeconds(5)) ? 'down' : 'extra',
        };
    }

    /** Até quando um pagamento conta como entrada: o lançamento ou, sem pagamento dele, o primeiro pagamento depois. */
    private static function downPaymentTime(Sale $sale): ?CarbonInterface
    {
        if (! $sale->launched_at) {
            return null;
        }
        $first = $sale->payments
            ->filter(fn (self $payment) => ! $payment->from_financial && $payment->stage !== 'delivery' && $payment->created_at)
            ->min(fn (self $payment) => $payment->created_at);
        if (! $first || $first->lte($sale->launched_at->copy()->addSeconds(5))) {
            return $sale->launched_at;
        }

        return $first;
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
