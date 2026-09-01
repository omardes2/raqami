<?php

namespace Database\Factories;

use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employee_number' => 'EMP-'.Str::upper(Str::random(8)),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'employment_status' => 'active',
            'employment_type' => 'full_time',
            'hire_date' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'work_email' => fake()->unique()->companyEmail(),
            'status' => 'active',
        ];
    }
}
