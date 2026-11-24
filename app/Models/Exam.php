<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exame do cadastro da ótica (24/11/2026): nome e valor (ex.: Retinografia). Na ficha, marca-se o interesse do cliente
 * (`sales.exams`, com a cópia do nome e do valor); é feito na entrega e não entra no total.
 */
#[Fillable(['name', 'price'])]
class Exam extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
