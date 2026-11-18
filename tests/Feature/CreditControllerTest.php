<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo "Buy credits" buttons.
 */
class CreditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->post(route('credits.store'), ['dollars' => 5]);

        $response->assertRedirect(route('login'));
    }

    public function test_buying_a_pack_adds_exactly_that_many_dollars_through_the_ledger(): void
    {
        $user = User::factory()->create(); // $2.00 welcome credit

        $response = $this->actingAs($user)->from(route('generations.index'))->post(route('credits.store'), ['dollars' => 10]);

        $response->assertRedirect(route('generations.index'));
        $this->assertSame(12_000_000_000, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => CreditTransaction::PURCHASE,
            'amount_nanos' => 10_000_000_000,
        ]);
    }

    public function test_an_amount_that_is_not_offered_is_rejected_and_adds_nothing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('credits.store'), ['dollars' => 1000]);

        $response->assertSessionHasErrors('dollars');
        $this->assertSame(2_000_000_000, $user->fresh()->balance_nanos);
        $this->assertDatabaseMissing('credit_transactions', ['type' => CreditTransaction::PURCHASE]);
    }
}
