<?php

namespace App\Http\Resources\Admin;

use App\Models\LabOrder;
use App\Support\PhonePrivacy;
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
            // OS de Ótica: o nº do pedido na empresa (09/11/2026).
            'number' => $this->number,
            'lab' => ['id' => $this->lab->id, 'name' => $this->lab->name],
            'client' => ['id' => $this->client->id, 'name' => $this->client->name, ...PhonePrivacy::fields($this->client->phone_number, $request)],
            'user' => $this->user?->name,
            'city_visit' => $this->cityVisit ? [
                'id' => $this->cityVisit->id,
                'title' => $this->cityVisit->title,
                'city_id' => $this->cityVisit->city_id,
                'city' => $this->cityVisit->city?->name,
                'visit_date' => $this->cityVisit->visit_date?->toDateString(),
                'end_date' => $this->cityVisit->end_date?->toDateString(),
            ] : null,
            'payload' => $this->payload,
            'has_prescription' => $this->prescription_path !== null,
            'sent' => $this->sent_at !== null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'send_error' => $this->send_error,
            'lab_number' => $this->lab_number,
            // Lote do laboratório em que a OS entrou ("LT000045"), ou null (avulsa).
            // Óculos de uma venda (a promissória): a venda e o valor do óculos.
            'sale_id' => $this->sale_id,
            'price' => $this->price,
            'lab_batch' => $this->lab_batch,
            'status' => $this->status,
            'status_label' => $this->sent_at || $this->status === 'canceled' ? $this->status_label : 'Pendente de envio',
            // Salvo e não enviado: confere, edita e envia (04/11/2026).
            'pending' => $this->pending(),
            'awaits_frame' => $this->awaits_frame,
            'cancel_reason' => $this->cancel_reason,
            'cancelable' => $this->cancelable() && $this->status !== 'canceled',
            'status_at' => $this->status_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
