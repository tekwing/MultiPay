<?php
use Illuminate\Support\Facades\Route;
use Tekwing\MultiPay\Http\Controllers\PaymentWebhookController;
use Tekwing\MultiPay\Http\Controllers\DemoPaymentController;

Route::post('/payment/webhook/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->name('payment.webhook');

// Only expose the demo route in local development environments for security
if (app()->environment('local')) {
    Route::middleware('web')->group(function () {
        Route::get('/multipay/demo', [DemoPaymentController::class, 'showDemo']);
        Route::post('/multipay/demo/pay', [DemoPaymentController::class, 'processDemo']);
    });
}