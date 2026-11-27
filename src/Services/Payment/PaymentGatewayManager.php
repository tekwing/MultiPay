<?php

namespace Tekwing\MultiPay\Services\Payment;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Tekwing\MultiPay\Contracts\PaymentGatewayInterface;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Models\PaymentGateway;
use Tekwing\MultiPay\Services\Payment\Gateways\RazorpayGateway;
use Tekwing\MultiPay\Services\Payment\Gateways\StripeGateway;

class PaymentGatewayManager
{
    /**
     * All currently enabled gateways (admin turned on + status enabled).
     */
    public function enabledGateways(): Collection
    {
        return PaymentGateway::query()
            ->where('is_active', true)
            ->where('status', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve an enabled gateway model by optional code, config default, or first enabled.
     */
    public function resolveEnabledGateway(?string $code = null): PaymentGateway
    {
        $enabled = $this->enabledGateways();

        if ($enabled->isEmpty()) {
            throw new RuntimeException('No active payment gateway is enabled.');
        }

        if ($code !== null && $code !== '') {
            $normalized = strtolower(trim($code));
            $matched = $enabled->firstWhere('code', $normalized);

            if (!$matched) {
                throw new InvalidArgumentException("Payment gateway [{$code}] is not active or does not exist.");
            }

            return $matched;
        }

        $defaultCode = strtolower((string) config('payment.default', ''));

        if ($defaultCode !== '') {
            $defaultMatched = $enabled->firstWhere('code', $defaultCode);
            if ($defaultMatched) {
                return $defaultMatched;
            }
        }

        return $enabled->first();
    }

    /**
     * Driver for a new payment using optional chosen gateway code.
     */
    public function driver(?string $code = null): PaymentGatewayInterface
    {
        $gatewayModel = $this->resolveEnabledGateway($code);

        return $this->makeDriver($gatewayModel);
    }

    /**
     * Driver for an existing payment record (does not block refunds/verification if later toggled off).
     */
    public function driverForPayment(Payment $payment): PaymentGatewayInterface
    {
        return $this->driverByCode($payment->gateway);
    }

    /**
     * Driver by gateway code for verification, refunds, or webhooks.
     */
    public function driverByCode(string $code): PaymentGatewayInterface
    {
        $normalized = strtolower(trim($code));

        $gatewayModel = PaymentGateway::query()
            ->where('code', $normalized)
            ->first();

        if (!$gatewayModel) {
            throw new InvalidArgumentException("Unknown payment gateway: {$code}");
        }

        return $this->makeDriver($gatewayModel);
    }

    protected function makeDriver(PaymentGateway $gatewayModel): PaymentGatewayInterface
    {
        return match ($gatewayModel->code) {
            'razorpay' => new RazorpayGateway($gatewayModel),
            'stripe' => new StripeGateway($gatewayModel),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$gatewayModel->code}"),
        };
    }
}