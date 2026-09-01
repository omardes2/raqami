<?php

namespace Database\Factories;

use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        $name = fake()->unique()->city().' Branch';

        return [
            'name' => $name,
            'code' => 'BR-'.Str::upper(Str::random(6)),
            'country_code' => 'SA',
            'city' => fake()->city(),
            'timezone' => 'Asia/Riyadh',
            'is_headquarters' => false,
            'status' => 'active',
        ];
    }

    public function headquarters(): static
    {
        return $this->state(fn () => ['is_headquarters' => true]);
    }
}
