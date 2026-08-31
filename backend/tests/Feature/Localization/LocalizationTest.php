<?php

namespace Tests\Feature\Localization;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_locales_endpoint_reports_directions(): void
    {
        $response = $this->getJson('/api/locales')->assertOk();

        $byCode = collect($response->json('locales'))->keyBy('code');
        $this->assertSame('rtl', $byCode['ar']['direction']);
        $this->assertSame('ltr', $byCode['en']['direction']);
    }

    public function test_user_locale_drives_text_direction(): void
    {
        $arabic = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($arabic)->getJson('/api/me')
            ->assertOk()->assertJsonPath('direction', 'rtl');

        $english = User::factory()->create(['locale' => 'en']);
        $this->actingAs($english)->getJson('/api/me')
            ->assertOk()->assertJsonPath('direction', 'ltr');
    }

    public function test_locale_switch_persists_for_the_user(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->patchJson('/api/me', ['locale' => 'ar'])
            ->assertOk()->assertJsonPath('direction', 'rtl');

        $this->assertSame('ar', $user->fresh()->locale);

        // Subsequent request reflects the persisted locale.
        $this->actingAs($user)->getJson('/api/me')
            ->assertJsonPath('locale', 'ar')
            ->assertJsonPath('direction', 'rtl');
    }
}
