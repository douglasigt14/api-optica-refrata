<?php

namespace App\Http\Resources\Admin;

use App\Models\Client;
use App\Support\PhonePrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cpf' => $this->cpf,
            // Sigilo (12/11/2026): o número só para o admin; os outros recebem o mascarado.
            ...PhonePrivacy::fields($this->phone_number, $request),
            'address' => $this->address,
            'birth_date' => $this->birth_date?->toDateString(),
            // Idade hoje (pela data de nascimento ou pela informada).
            'age' => $this->currentAge(),
            'wears_glasses' => (bool) $this->wears_glasses,
            'comorbidities' => (object) ($this->comorbidities ?? []),
            'referral_source' => $this->referral_source,
            // Última compra, quem indicou e observações (12/11/2026; vieram da planilha da Perfeita Visão).
            'last_purchase_date' => $this->last_purchase_date?->toDateString(),
            'last_purchase_amount' => $this->last_purchase_amount === null ? null : (float) $this->last_purchase_amount,
            'referred_by' => $this->referred_by,
            'notes' => $this->notes,
            'city' => $this->city?->only(['id', 'name', 'state']),
            'lead_id' => $this->lead_id,
            'lab_orders_count' => $this->whenCounted('labOrders'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
