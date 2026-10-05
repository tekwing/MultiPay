<?php

namespace Tekwing\MultiPay\Services\Gateways;

use Tekwing\MultiPay\Contracts\PaymentGatewayInterface;
use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Models\PaymentGateway;
use Tekwing\MultiPay\Events\PaymentSucceeded;
use Tekwing\MultiPay\Events\PaymentFailed;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeGateway implements PaymentGatewayInterface
{
    protected function gateway(): PaymentGateway
    {
        return PaymentGateway::query()
            ->where('code', 'stripe')
            ->where('status', true)
            ->firstOrFail();
    }

    protected function stripe(): StripeClient
    {
        $gateway = $this->gateway();
        $secretKey = $gateway->credentials['secret_key'] ?? null;

        if (!$secretKey) {
            throw new \Exception('Stripe secret key is not configured.');
        }

        return new StripeClient($secretKey);
    }

    /**
     * Create Stripe Checkout Session.
     */
    public function initiate(Payable $payable): array
    {
        $stripe = $this->stripe();
        $amount = $payable->getPayableAmount();
        $description = $payable->getPayableDescription();

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'inr',
                        'product_data' => [
                            'name' => $description,
                        ],
                        'unit_amount' => (int) round($amount * 100),
                    ],
                    'quantity' => 1,
                ],
            ],
            'customer_email' => auth()->user()->email ?? null,
            'metadata' => [
                'payable_type' => get_class($payable),
                'payable_id' => (string) $payable->getKey(),
            ],
            'success_url' => route('payment.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('payment.cancel'),
        ]);

        // INSERT SEPARATE PAYMENT RECORD (Polymorphic)
        $payable->payments()->create([
            'gateway' => 'stripe',
            'gateway_order_id' => $session->id,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        return [
            'gateway' => 'stripe',
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Verify Stripe Checkout payment (Synchronous).
     */
    public function verify(array $data): bool
    {
        if (empty($data['session_id'])) {
            return false;
        }

        try {
            $stripe = $this->stripe();
            $session = $stripe->checkout->sessions->retrieve($data['session_id']);

            if ($session->payment_status !== 'paid') {
                return false;
            }

            // 1. Look up the specific PAYMENT attempt
            $payment = Payment::where('gateway_order_id', $session->id)->first();

            if (!$payment) {
                return false;
            }

            // 2. Idempotency check
            if ($payment->status === 'paid') {
                return true;
            }

            $paymentIntent = is_string($session->payment_intent) 
                ? $session->payment_intent 
                : null;

            // 3. Update the package's Payment record
            $payment->update([
                'status' => 'paid',
                'gateway_payment_id' => $paymentIntent,
            ]);

            // 4. Fire the Success Event
            event(new PaymentSucceeded($payment));

            return true;

        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    /**
     * Stripe webhook.
     */
    public function handleWebhook(
        string $payload,
        array $headers = []
    ): bool {

        $gateway = $this->gateway();
        $webhookSecret = $gateway->credentials['webhook_secret'] ?? null;

        if (!$webhookSecret) {
            return false;
        }

        $signature = $headers['Stripe-Signature']
            ?? $headers['stripe-signature']
            ?? null;

        if (!$signature) {
            return false;
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            report($e);
            return false;
        }

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                return $this->completeCheckout($event->data->object);

            case 'checkout.session.async_payment_failed':
                return $this->failCheckout($event->data->object);
        }

        return true;
    }

    /**
     * Mark Stripe checkout as paid via Webhook.
     */
    protected function completeCheckout($session): bool
    {
        if (($session->payment_status ?? null) !== 'paid') {
            return true;
        }

        // 1. Look up the specific PAYMENT attempt
        $payment = Payment::where('gateway_order_id', $session->id)->first();

        if (!$payment) {
            return false;
        }

        // 2. Idempotency check
        if ($payment->status === 'paid') {
            return true;
        }

        $paymentIntent = is_string($session->payment_intent) 
            ? $session->payment_intent 
            : null;

        // 3. Update the package's Payment record
        $payment->update([
            'status' => 'paid',
            'gateway_payment_id' => $paymentIntent,
        ]);

        // 4. Fire the Success Event
        event(new PaymentSucceeded($payment));

        return true;
    }

    /**
     * Mark delayed Stripe payment as failed via Webhook.
     */
    protected function failCheckout($session): bool
    {
        // 1. Look up the specific PAYMENT attempt
        $payment = Payment::where('gateway_order_id', $session->id)->first();

        if (!$payment) {
            return false;
        }

        if ($payment->status !== 'paid') {
            // Mark this specific attempt as failed
            $payment->update([
                'status' => 'failed',
            ]);

            // Fire the Failure Event
            event(new PaymentFailed($payment));
        }

        return true;
    }

    /**
     * Refund Stripe payment.
     */
    public function refund(
        string $paymentId,
        ?float $amount = null
    ): array {

        $stripe = $this->stripe();

        $data = [
            'payment_intent' => $paymentId,
        ];

        if ($amount !== null) {
            $data['amount'] = (int) round($amount * 100);
        }

        $refund = $stripe->refunds->create($data);

        return $refund->toArray();
    }
}