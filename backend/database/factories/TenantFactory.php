<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' LLC',
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'country_code' => 'SA',
            'timezone' => 'Asia/Riyadh',
            'default_locale' => 'en',
            'default_currency' => 'SAR',
            'status' => 'active',
        ];
    }
}
