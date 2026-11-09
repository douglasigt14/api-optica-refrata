<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
