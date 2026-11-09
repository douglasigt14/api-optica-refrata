<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido da ótica a um laboratório do grupo (vira OS lá; passo 3 do roteiro). `number` = a **OS de Ótica**, sequência
 * de cada empresa (09/11/2026; vai ao laboratório como `optical_order_number`). O `id` (único na instalação) é o que o
 * laboratório guarda como `external_id` (mandar de novo não duplica). `payload` = o pedido como foi mandado (receita, armação, lente,
 * tratamentos). Pendente de envio (04/11/2026: salvar não envia): `sent_at` null; o erro do último envio em `send_error`. A situação vem do laboratório.
 * `city_visit_id` = o atendimento escolhido (vai junto; com lote aberto dele lá, a OS entra no lote: `lab_batch`).
 */
#[Fillable([
    'lab_connection_id', 'client_id', 'city_visit_id', 'sale_id', 'price', 'user_id', 'payload', 'prescription_path', 'sent_at', 'send_error',
    'lab_number', 'lab_batch', 'status', 'status_label', 'awaits_frame', 'cancel_reason', 'status_at',
])]
class LabOrder extends Model
{
    use BelongsToCompany;

    /** Situações em que a ótica ainda cancela (nenhuma lente separada no laboratório). */
    public const CANCELABLE = ['awaiting_measures', 'awaiting_lens', 'open'];

    /**
     * A OS de Ótica nasce com o próximo número da empresa (o maior dela + 1).
     */
    protected static function booted(): void
    {
        static::creating(function (LabOrder $order) {
            if ($order->getAttributes()['number'] ?? null) {
                return;
            }
            // Trava a empresa (dentro da transação de quem cria) para dois pedidos juntos não pegarem o mesmo número.
            Company::query()->whereKey($order->company_id)->lockForUpdate()->value('id');
            $order->number = (int) static::withoutGlobalScopes()->where('company_id', $order->company_id)->max('number') + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'payload' => 'array',
            'sent_at' => 'datetime',
            'status_at' => 'datetime',
            'awaits_frame' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    /**
     * Salvo e ainda não enviado ao laboratório (04/11/2026: o pedido é salvo, conferido e só depois enviado). Só ele se edita.
     */
    public function pending(): bool
    {
        return $this->sent_at === null && $this->status !== 'canceled';
    }

    public function cancelable(): bool
    {
        return $this->sent_at === null || in_array($this->status, self::CANCELABLE, true);
    }

    /**
     * Grava a situação que o laboratório mandou (no aviso, no envio ou na consulta).
     *
     * @param  array<string, mixed>  $data
     */
    public function applyLabStatus(array $data): void
    {
        $this->update([
            'lab_number' => $data['number'] ?? $this->lab_number,
            'lab_batch' => array_key_exists('batch', $data) ? $data['batch'] : $this->lab_batch,
            'status' => $data['status'] ?? $this->status,
            'status_label' => $data['status_label'] ?? $this->status_label,
            'awaits_frame' => (bool) ($data['awaits_frame'] ?? $this->awaits_frame),
            'cancel_reason' => $data['cancel_reason'] ?? $this->cancel_reason,
            'status_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<LabConnection, $this>
     */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(LabConnection::class, 'lab_connection_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<CityVisit, $this>
     */
    /**
     * Venda (a promissória) de que este óculos faz parte; null = pedido sem venda.
     *
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cityVisit(): BelongsTo
    {
        return $this->belongsTo(CityVisit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
