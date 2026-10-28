<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            'prospector' => $this->whenLoaded('prospector', fn () => [
                'id' => $this->prospector->id,
                'name' => $this->prospector->name,
                'birth_date' => $this->prospector->birth_date->toDateString(),
                'phone_number' => $this->prospector->phone_number,
                'pix_key' => $this->prospector->pix_key,
                'instagram_handle' => $this->prospector->instagram_handle,
                'city' => $this->prospector->city->only(['id', 'name', 'state']),
                // A ótica do parceiro (nome no app e no menu).
                'company' => $this->prospector->company ? ['id' => $this->prospector->company->id, 'name' => $this->prospector->company->displayName()] : null,
            ]),
        ];
    }
}
