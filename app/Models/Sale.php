<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Venda da ótica — a promissória (07/11/2026): o cliente que paga, os óculos (pedidos ao laboratório, cada um com o
 * paciente e o valor) e os pagamentos (entrada e o restante na entrega).
 *
 * Situação: **rascunho** (montando) → **lançada** (`launched_at`: tem o título a receber no Financeiro) → **entregue**
 * (`delivered_at`), ou **cancelada**. Óculos e valores mudam até algum óculos ir ao laboratório (`locked`).
 */
#[Fillable(['city_visit_id', 'client_id', 'expected_delivery_date', 'notes', 'launched_at', 'delivered_at', 'canceled_at', 'cancel_reason', 'financial_synced_at', 'financial_error', 'user_id'])]
class Sale extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'expected_delivery_date' => 'date',
            'launched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'canceled_at' => 'datetime',
            'financial_synced_at' => 'datetime',
        ];
    }

    public function status(): string
    {
        return match (true) {
            $this->canceled_at !== null => 'canceled',
            $this->delivered_at !== null => 'delivered',
            $this->launched_at !== null => 'launched',
            default => 'draft',
        };
    }

    /** Algum óculos já foi ao laboratório: óculos e valores não mudam mais (nem cancela). */
    public function locked(): bool
    {
        return $this->orders->contains(fn (LabOrder $order) => $order->sent_at !== null);
    }

    /** Óculos que contam (os cancelados não). */
    public function activeOrders(): Collection
    {
        return $this->orders->filter(fn (LabOrder $order) => $order->status !== 'canceled')->values();
    }

    public function totalCents(): int
    {
        return (int) $this->activeOrders()->sum(fn (LabOrder $order) => (int) round(((float) $order->price) * 100));
    }

    public function paidCents(): int
    {
        return (int) $this->payments->sum(fn (SalePayment $payment) => (int) round(((float) $payment->amount) * 100));
    }

    public function remainingCents(): int
    {
        return max(0, $this->totalCents() - $this->paidCents());
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<CityVisit, $this> */
    public function cityVisit(): BelongsTo
    {
        return $this->belongsTo(CityVisit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LabOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(LabOrder::class)->orderBy('id');
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }
}
