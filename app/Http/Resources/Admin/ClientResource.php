<?php

namespace App\Http\Resources\Admin;

use App\Models\Client;
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
            'phone_number' => $this->phone_number,
            'birth_date' => $this->birth_date?->toDateString(),
            'city' => $this->city?->only(['id', 'name', 'state']),
            'lead_id' => $this->lead_id,
            'lab_orders_count' => $this->whenCounted('labOrders'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
