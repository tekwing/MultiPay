<?php
use Illuminate\Support\Facades\Route;
use YourName\PaymentGateway\Http\Controllers\PaymentWebhookController;

Route::post('/payment/webhook/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->name('payment.webhook');