<?php

namespace Database\Factories;

use App\Modules\Organization\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' Team',
            'code' => 'TM-'.Str::upper(Str::random(6)),
            'status' => 'active',
        ];
    }
}
