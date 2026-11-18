<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded test user goes through the same welcome-credit path as a real sign-up.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_test_user_starts_with_the_two_dollar_welcome_credit(): void
    {
        $this->seed();

        $this->assertSame(2_000_000_000, User::query()->where('email', 'test@example.com')->sole()->balance_nanos);
    }
}
