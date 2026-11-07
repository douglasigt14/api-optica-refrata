<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento de uma venda: `stage` = `down` (entrada) ou `delivery` (na entrega); `method` = `cash` (espécie), `pix` ou
 * `card` (com `installments`, as vezes no cartão).
 */
#[Fillable(['sale_id', 'stage', 'method', 'amount', 'installments', 'paid_on', 'user_id'])]
class SalePayment extends Model
{
    use BelongsToCompany;

    public const METHODS = ['cash' => 'Espécie', 'pix' => 'PIX', 'card' => 'Cartão'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'installments' => 'integer'];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
