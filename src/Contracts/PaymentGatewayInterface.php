<?php

namespace Tekwing\MultiPay\Contracts;

interface PaymentGatewayInterface
{
    public function initiate(Payable $payable): array;
    public function verify(array $data): bool;
    public function refund(string $paymentId, ?float $amount = null): array;
    public function handleWebhook(string $payload, array $headers = []): bool;
}