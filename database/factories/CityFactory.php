<?php

namespace Database\Factories;

use App\Models\City;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ibge_code' => fake()->unique()->numberBetween(1100000, 5399999),
            'name' => fake()->unique()->city(),
            'state' => 'CE',
        ];
    }

    /**
     * Atendida pela ótica escolhida (a dos testes); `inactive()` = nenhuma ótica atende.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (City $city) {
            if ($company = app(CurrentCompany::class)->get()) {
                $city->companies()->syncWithoutDetaching([$company->id]);
            }
        });
    }

    /**
     * Cidade que a ótica não atende (não aparece no cadastro).
     */
    public function inactive(): static
    {
        return $this->afterCreating(fn (City $city) => $city->companies()->detach());
    }
}
