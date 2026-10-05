<?php

namespace Tekwing\MultiPay\Providers;

use Illuminate\Support\ServiceProvider;
use Tekwing\MultiPay\Services\PaymentGatewayManager;
use Tekwing\MultiPay\Services\PaymentService;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge default config
        $this->mergeConfigFrom(__DIR__ . '/../../config/payment.php', 'payment');

        // Bind the manager and service to the container
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            return new PaymentGatewayManager();
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService($app->make(PaymentGatewayManager::class));
        });
    }

    public function boot(): void
    {
        // Load package routes and migrations automatically
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Allow developers to publish config and migrations to their own app
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/payment.php' => config_path('payment.php'),
            ], 'payment-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations' => database_path('migrations'),
            ], 'payment-migrations');
        }
    }
}