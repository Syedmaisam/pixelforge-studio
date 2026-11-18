<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            // Our own id. Used as the Idempotency-Key and sent in metadata.local_id,
            // so retries never duplicate and early webhooks can find this row.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique(); // gen_...

            $table->string('status')->index(); // pending|queued|processing|succeeded|failed

            // What the user asked for
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money, all in integer nanodollars (1 USD = 1,000,000,000)
            $table->bigInteger('estimated_min_nanos');     // user price, cheapest case
            $table->bigInteger('estimated_max_nanos');     // user price, worst case (= the credit hold)
            $table->bigInteger('provider_cost_nanos')->nullable(); // what Pixelforge charged us
            $table->bigInteger('charged_nanos')->nullable();       // what we charged the user (0 when failed)

            // Copied from Pixelforge so every number can be re-checked later
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Pixelforge's own timestamps (UTC). completed_at decides the billing day.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
