<?php

namespace Database\Factories;

use App\Modules\Organization\Models\JobTitle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<JobTitle> */
class JobTitleFactory extends Factory
{
    protected $model = JobTitle::class;

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->jobTitle(),
            'code' => 'JT-'.Str::upper(Str::random(6)),
            'level' => fake()->randomElement(['junior', 'mid', 'senior']),
            'status' => 'active',
        ];
    }
}
