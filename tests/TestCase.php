<?php

namespace Tests;

use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\DefaultLeadStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Ótica dos testes (a "Otica Exclusive"): os dados criados direto e os usuários das fábricas são dela. */
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->company = Company::factory()->create(['name' => 'Otica Exclusive Ltda', 'trade_name' => 'Otica Exclusive', 'invite_code' => 'EXCLUSIVE']);
            DefaultLeadStatuses::createFor($this->company);
            app(CurrentCompany::class)->set($this->company);
        }
    }
}
