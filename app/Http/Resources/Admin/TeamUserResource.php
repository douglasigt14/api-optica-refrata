<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Usuário da equipe interna. `from_console`: cadastrado (ou importado) pelo Console, que manda de novo nome, senha e
 * papéis quando alguém salva a pessoa lá.
 *
 * @mixin User
 */
class TeamUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'from_console' => $this->console_id !== null,
            'role' => $this->role,
            'role_label' => $this->role?->label(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
