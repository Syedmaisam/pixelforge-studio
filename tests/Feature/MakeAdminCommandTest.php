<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php artisan app:make-admin {email}
 */
class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_gives_the_user_access_to_the_admin_report(): void
    {
        $user = User::factory()->create(['email' => 'reviewer@example.test']);

        $this->artisan('app:make-admin', ['email' => 'reviewer@example.test'])
            ->expectsOutput('reviewer@example.test can now open /admin/report.')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_an_unknown_email_fails_and_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:make-admin', ['email' => 'nobody@example.test'])
            ->expectsOutput('No user with the email nobody@example.test.')
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_admin);
    }
}
