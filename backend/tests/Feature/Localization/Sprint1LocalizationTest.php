<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class Sprint1LocalizationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_employee_validation_messages_are_localized(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        // An owner acting in Arabic gets Arabic validation errors.
        $arabicOwner = $this->memberWithRole($tenant, 'admin', 'company', null, ['locale' => 'ar']);
        $ar = $this->actingAs($arabicOwner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['last_name' => 'Only'])
            ->assertStatus(422)->json('errors.first_name.0');
        $this->assertStringContainsString('مطلوب', $ar); // "required" in Arabic

        // An owner acting in English gets English validation errors.
        $englishOwner = $this->memberWithRole($tenant, 'admin', 'company', null, ['locale' => 'en']);
        $en = $this->actingAs($englishOwner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['last_name' => 'Only'])
            ->assertStatus(422)->json('errors.first_name.0');
        $this->assertStringContainsString('required', $en);
    }
}
