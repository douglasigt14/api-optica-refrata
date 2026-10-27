<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => fn () => Group::query()->create(['name' => fake()->company()])->id,
            'name' => fake()->company(),
            'document' => fake()->unique()->numerify('##############'),
            'invite_code' => fake()->unique()->bothify('OTICA####'),
            'active' => true,
        ];
    }
}
