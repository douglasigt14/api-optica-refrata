<?php

namespace App\Http\Resources\Admin;

use App\Models\Lead;
use App\Support\PhonePrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Indicação vista pela equipe: com parceiro, situação, dia do exame e (no detalhe) os contatos e o histórico de situações.
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Sigilo (12/11/2026): o número só para o admin; os outros recebem o mascarado.
            ...PhonePrivacy::fields($this->phone_number, $request),
            'stage' => $this->stage,
            'status' => new LeadStatusResource($this->status),
            'appointment_date' => $this->appointment_date?->toDateString(),
            'city' => $this->city->only(['id', 'name', 'state']),
            'visit' => $this->visit ? [
                'id' => $this->visit->id,
                'title' => $this->visit->title,
                'visit_date' => $this->visit->visit_date->toDateString(),
                'end_date' => $this->visit->end_date?->toDateString(),
                'status' => $this->visit->status,
            ] : null,
            'prospector' => $this->prospector->only(['id', 'name', 'phone_number']),
            'contacts_count' => $this->whenCounted('contacts'),
            'contacts' => LeadContactResource::collection($this->whenLoaded('contacts')),
            'status_changes' => LeadStatusChangeResource::collection($this->whenLoaded('statusChanges')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
