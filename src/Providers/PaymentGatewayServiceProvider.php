<?php

namespace Tekwing\MultiPay\Providers;

use Illuminate\Support\ServiceProvider;
use Tekwing\MultiPay\Services\Payment\PaymentGatewayManager;
use Tekwing\MultiPay\Services\Payment\PaymentService;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/payment.php', 'payment');

        $this->app->singleton(PaymentGatewayManager::class, function () {
            return new PaymentGatewayManager();
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService($app->make(PaymentGatewayManager::class));
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'multipay');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../resources/views' => resource_path('views/vendor/multipay'),
            ], 'payment-views');

            $this->publishes([
                __DIR__ . '/../../config/payment.php' => config_path('payment.php'),
            ], 'payment-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations' => database_path('migrations'),
            ], 'payment-migrations');
        }
    }
}