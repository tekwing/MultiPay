<?php

namespace Tekwing\MultiPay\Services\Payment;

use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Services\Payment\PaymentGatewayManager;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $manager
    ) {}

    public function initiate(Payable $payable): array
    {
        // Get currently active gateway
        $gateway = $this->manager->getActiveGateway();

        // Create ONE local payment attempt
        $payment = $payable->payments()->create([
            'gateway' => $gateway->code,
            'amount' => $payable->getPayableAmount(),
            'status' => 'pending',
        ]);

        // Let the selected gateway process it
        $response = $this->manager
            ->driverByCode($gateway->code)
            ->initiate($payment, $payable);

        // Add package payment ID
        $response['local_payment_id'] = $payment->id;
        $response['gateway'] = $gateway->code;

        return $response;
    }

    public function verify(
        Payment $payment,
        array $data
    ): bool {
        return $this->manager
            ->driverForPayment($payment)
            ->verify($data);
    }

    public function refund(
        Payment $payment,
        string $paymentId,
        ?float $amount = null
    ): array {
        return $this->manager
            ->driverForPayment($payment)
            ->refund($paymentId, $amount);
    }

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