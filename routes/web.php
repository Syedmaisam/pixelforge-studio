<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;


use Illuminate\Support\Facades\Artisan;

Route::inertia('/', 'Welcome')->name('home');

// Only "auth", not "verified": reviewers can use the studio without a mail server.
Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', ReportController::class)
        ->middleware('can:view-admin-report')
        ->name('admin.report');
});

// Called by Pixelforge, not by a browser: no auth, and CSRF is disabled for it in bootstrap/app.php.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';



Route::get('/run-migrations', function () {
    Artisan::call('migrate', [
        '--force' => true, // Required when APP_ENV is set to production
    ]);

    return 'Migrations completed successfully: ' . Artisan::output();
});
