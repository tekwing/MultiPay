<?php

namespace Tekwing\MultiPay\Services\Payment;

use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\Payment;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $manager
    ) {}

    /**
     * Initiate a payment using the chosen gateway code (or active/default gateway).
     */
    public function initiate(Payable $payable, ?string $gatewayCode = null): array
    {
        $gateway = $this->manager->resolveEnabledGateway($gatewayCode);
        $currency = strtoupper(trim($payable->getPayableCurrency() ?: 'INR'));

        $payment = $payable->payments()->create([
            'gateway' => $gateway->code,
            'amount' => $payable->getPayableAmount(),
            'status' => 'pending',
            'metadata' => [
                'currency' => $currency,
            ],
        ]);

        $response = $this->manager
            ->makeDriver($gateway)
            ->initiate($payment, $payable);

        $response['local_payment_id'] = $payment->id;
        $response['gateway'] = $gateway->code;
        $response['currency'] = $currency;

        return $response;
    }

    public function verify(Payment $payment, array $data): bool
    {
        return $this->manager
            ->driverForPayment($payment)
            ->verify($data);
    }

    public function refund(
        Payment $payment,
        string $paymentId,
        ?float $amount = null
    ): array {
        $currency = strtoupper(
            (string) ($payment->metadata['currency']
                ?? (method_exists($payment->payable, 'getPayableCurrency')
                    ? $payment->payable->getPayableCurrency()
                    : 'INR'))
        );

        return $this->manager
            ->driverForPayment($payment)
            ->refund($paymentId, $amount, $currency);
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
            ->resolveEnabledGateway()
            ->code;
    }
}