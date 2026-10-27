<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Acesso de alguém da equipe a uma ótica, com o papel dele lá.
 */
class CompanyUser extends Pivot
{
    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => UserRole::class];
    }
}
