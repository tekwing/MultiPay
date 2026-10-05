<?php

namespace Tekwing\MultiPay\Services\Payment; // Ensure this matches your folder structure (src/Services/)

use Tekwing\MultiPay\Contracts\PaymentGatewayInterface;
use Tekwing\MultiPay\Models\PaymentGateway;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Services\Gateways\RazorpayGateway;
use Tekwing\MultiPay\Services\Gateways\StripeGateway;
use Exception;

class PaymentGatewayManager
{
    public function getActiveGateway(): PaymentGateway
    {
        $gateway = PaymentGateway::query()
            ->where('is_active', true)
            ->where('status', true)
            ->first();

        if (!$gateway) {
            throw new Exception('No active payment gateway configured.');
        }

        return $gateway;
    }

    /**
     * Driver for creating a NEW payment.
     */
    public function driver(): PaymentGatewayInterface
    {
        return $this->driverByCode(
            $this->getActiveGateway()->code
        );
    }

    /**
     * Driver based on an existing package Payment record.
     */
    public function driverForPayment(Payment $payment): PaymentGatewayInterface
    {
        return $this->driverByCode($payment->gateway);
    }

    /**
     * Driver based on gateway code.
     */
    public function driverByCode(string $code): PaymentGatewayInterface
    {
        // Optionally Cache this query forever, clear cache when Admin updates settings
        $gatewayModel = PaymentGateway::where('code', $code)->first(); 

        return match ($code) {
            'razorpay' => new RazorpayGateway($gatewayModel),
            'stripe' => new StripeGateway($gatewayModel),
            default => throw new Exception("Unsupported payment gateway: {$code}"),
        };
    }
}