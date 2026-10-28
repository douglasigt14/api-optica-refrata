<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Laboratório do grupo ligado a esta ótica pelo Console (passo 3 do roteiro), pelo ID da empresa laboratório no Console:
 * o endereço e a chave para mandar pedidos (criptografada) e o hash da chave com que o laboratório avisa a situação das
 * OS. Nunca em `.env`. Sem endereço e chave = ligação pela metade (ainda não manda pedidos).
 */
#[Fillable(['lab_console_id', 'name', 'url', 'token', 'inbound_token_hash', 'connected_at'])]
#[Hidden(['token', 'inbound_token_hash'])]
class LabConnection extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function connected(): bool
    {
        return filled($this->url) && filled($this->token);
    }
}
