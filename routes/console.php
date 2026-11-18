<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll Pixelforge for generations whose webhooks never arrived.
Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();

// php artisan app:make-admin you@example.com
Artisan::command('app:make-admin {email}', function (string $email) {
    $user = User::query()->where('email', $email)->first();

    if ($user === null) {
        $this->error("No user with the email {$email}.");

        return 1;
    }

    $user->forceFill(['is_admin' => true])->save(); // is_admin is not mass-assignable, on purpose
    $this->info("{$user->email} can now open /admin/report.");

    return 0;
})->purpose('Give a user access to the admin report');
