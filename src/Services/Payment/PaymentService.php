<?php

namespace Tekwing\MultiPay\Services;

use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\Payment;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $manager
    ) {}

    public function initiate(Payable $payable): array
    {
        return $this->manager->driver()->initiate($payable);
    }

    // Pass the specific Payment record instead of the Order
    public function verify(Payment $payment, array $data): bool
    {
        return $this->manager->driverForPayment($payment)->verify($data);
    }

    /**
     * Refund using the gateway associated with the specific payment attempt.
     */
    public function refund(
        Payment $payment,
        string $paymentId,
        ?float $amount = null
    ): array {
        return $this->manager
            ->driverForPayment($payment)
            ->refund($paymentId, $amount);
    }

    /**
     * Handle gateway-specific webhook.
     */
    public function handleWebhook(
        string $gatewayCode,
        string $payload,
        array $headers = []
    ): bool {
        return $this->manager
            ->driverByCode($gatewayCode)
            ->handleWebhook($payload, $headers);
    }

    public function activeGateway(): string
    {
        return $this->manager
            ->getActiveGateway()
            ->code;
    }
}