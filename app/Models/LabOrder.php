<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido da ótica a um laboratório do grupo (vira OS lá; passo 3 do roteiro). O `id` é o nº do pedido que o laboratório
 * guarda (`external_id`: mandar de novo não duplica). `payload` = o pedido como foi mandado (receita, armação, lente,
 * tratamentos). Não enviado: `sent_at` null e o erro em `send_error` ("Enviar de novo"). A situação vem do laboratório.
 */
#[Fillable([
    'lab_connection_id', 'client_id', 'user_id', 'payload', 'prescription_path', 'sent_at', 'send_error',
    'lab_number', 'status', 'status_label', 'awaits_frame', 'cancel_reason', 'status_at',
])]
class LabOrder extends Model
{
    use BelongsToCompany;

    /** Situações em que a ótica ainda cancela (nenhuma lente separada no laboratório). */
    public const CANCELABLE = ['awaiting_measures', 'awaiting_lens', 'open'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'status_at' => 'datetime',
            'awaits_frame' => 'boolean',
        ];
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
