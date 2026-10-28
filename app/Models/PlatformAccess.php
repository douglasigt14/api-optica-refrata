<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A chave do Console da Refrata neste sistema (uma linha só; guarda só o hash). Criada no pareamento pela tela Módulos
 * do Console; trocada pelo próprio Console. Sem linha = ainda não pareado.
 */
#[Fillable(['token_hash', 'paired_at'])]
class PlatformAccess extends Model
{
    protected $table = 'platform_access';

    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function accepts(string $token): bool
    {
        $access = static::current();

        return $access !== null && $token !== '' && hash_equals($access->token_hash, static::hash($token));
    }
}
