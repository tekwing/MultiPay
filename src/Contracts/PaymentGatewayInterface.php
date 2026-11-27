<?php

namespace Tekwing\MultiPay\Contracts;

use Tekwing\MultiPay\Models\Payment;

interface PaymentGatewayInterface
{
    public function initiate(
        Payment $payment,
        Payable $payable
    ): array;

    public function verify(array $data): bool;

    public function refund(
        string $paymentId,
        ?float $amount = null,
        string $currency = 'INR'
    ): array;

    public function handleWebhook(
        string $payload,
        array $headers = []
    ): bool;
}