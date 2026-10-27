<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Cidade que uma ótica atende.
 */
class CompanyCity extends Pivot
{
    protected $table = 'company_city';

    public $incrementing = true;
}
