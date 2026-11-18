<?php

namespace Tests\Unit;

use App\Enums\GenerationStatus;
use PHPUnit\Framework\TestCase;

/**
 * The forward-only rule that makes duplicate and out-of-order webhooks harmless.
 */
class GenerationStatusTest extends TestCase
{
    public function test_status_moves_forward(): void
    {
        $this->assertTrue(GenerationStatus::Pending->canMoveTo(GenerationStatus::Processing));
        $this->assertTrue(GenerationStatus::Queued->canMoveTo(GenerationStatus::Succeeded));
        $this->assertTrue(GenerationStatus::Processing->canMoveTo(GenerationStatus::Failed));
    }

    public function test_status_never_moves_backwards_or_sideways(): void
    {
        $this->assertFalse(GenerationStatus::Processing->canMoveTo(GenerationStatus::Queued));
        $this->assertFalse(GenerationStatus::Queued->canMoveTo(GenerationStatus::Queued));
    }

    public function test_final_states_never_change(): void
    {
        $this->assertFalse(GenerationStatus::Succeeded->canMoveTo(GenerationStatus::Failed));
        $this->assertFalse(GenerationStatus::Failed->canMoveTo(GenerationStatus::Succeeded));
        $this->assertTrue(GenerationStatus::Succeeded->isFinal());
        $this->assertFalse(GenerationStatus::Processing->isFinal());
    }
}
