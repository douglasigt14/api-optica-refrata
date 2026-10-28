<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo (o cliente da Refrata), espelho do Console (`console_id`). Bloqueado, ninguém do grupo entra.
 */
#[Fillable(['console_id', 'name', 'blocked_at'])]
class Group extends Model
{
    protected function casts(): array
    {
        return ['blocked_at' => 'datetime'];
    }

    public function blocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
