<?php

namespace App\Http\Resources\Admin;

use App\Models\LabOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pedido ao laboratório como a tela mostra: o laboratório, o cliente, o pedido mandado, o nº da OS e a situação lá.
 *
 * @mixin LabOrder
 */
class LabOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lab' => ['id' => $this->lab->id, 'name' => $this->lab->name],
            'client' => ['id' => $this->client->id, 'name' => $this->client->name, 'phone_number' => $this->client->phone_number],
            'user' => $this->user?->name,
            'payload' => $this->payload,
            'has_prescription' => $this->prescription_path !== null,
            'sent' => $this->sent_at !== null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'send_error' => $this->send_error,
            'lab_number' => $this->lab_number,
            'status' => $this->status,
            'status_label' => $this->sent_at ? $this->status_label : 'Não enviado',
            'awaits_frame' => $this->awaits_frame,
            'cancel_reason' => $this->cancel_reason,
            'cancelable' => $this->cancelable() && $this->status !== 'canceled',
            'status_at' => $this->status_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
