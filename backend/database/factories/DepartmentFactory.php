<?php

namespace Database\Factories;

use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' Department',
            'code' => 'DEP-'.Str::upper(Str::random(6)),
            'status' => 'active',
        ];
    }
}
