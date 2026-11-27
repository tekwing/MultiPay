<?php

namespace Tekwing\MultiPay\Services\Payment\Gateways;

use Exception;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Contracts\PaymentGatewayInterface;
use Tekwing\MultiPay\Events\PaymentFailed;
use Tekwing\MultiPay\Events\PaymentSucceeded;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Models\PaymentGateway;
use Throwable;
use UnexpectedValueException;

class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected ?PaymentGateway $gatewayModel = null
    ) {}

    protected function gateway(): PaymentGateway
    {
        if ($this->gatewayModel) {
            return $this->gatewayModel;
        }

        $this->gatewayModel = PaymentGateway::query()
            ->where('code', 'stripe')
            ->where('status', true)
            ->firstOrFail();

        return $this->gatewayModel;
    }

    protected function stripe(?PaymentGateway $gateway = null): StripeClient
    {
        $gateway = $gateway ?? $this->gateway();
        $secretKey = $gateway->credentials['secret_key'] ?? null;

        if (!$secretKey) {
            throw new Exception('Stripe secret key is not configured.');
        }

        return new StripeClient($secretKey);
    }

    protected function toMinorUnits(float $amount, string $currency): int
    {
        $zeroDecimal = ['JPY', 'KRW'];

        if (in_array(strtoupper($currency), $zeroDecimal, true)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    public function initiate(Payment $payment, Payable $payable): array
    {
        $gateway = $this->gateway();
        $stripe = $this->stripe($gateway);

        $amount = $payable->getPayableAmount();
        $currency = strtolower(trim($payable->getPayableCurrency() ?: 'USD'));
        $description = $payable->getPayableDescription();

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $description,
                        ],
                        'unit_amount' => $this->toMinorUnits($amount, $currency),
                    ],
                    'quantity' => 1,
                ],
            ],
            'customer_email' => auth()->user()?->email,
            'metadata' => [
                'payment_id' => (string) $payment->id,
                'payable_type' => get_class($payable),
                'payable_id' => (string) $payable->getKey(),
            ],
            'success_url' => route('payment.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('payment.cancel'),
        ]);

        $payment->update([
            'gateway_order_id' => $session->id,
        ]);

        return [
            'gateway' => 'stripe',
            'checkout_url' => $session->url,
            'session_id' => $session->id,
            'currency' => strtoupper($currency),
        ];
    }

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

            $paymentIntent = is_string($session->payment_intent)
                ? $session->payment_intent
                : null;

            $shouldDispatchSuccess = false;
            $lockedPayment = null;

            DB::transaction(function () use ($session, $paymentIntent, &$shouldDispatchSuccess, &$lockedPayment) {
                $payment = Payment::query()
                    ->where('gateway_order_id', $session->id)
                    ->lockForUpdate()
                    ->first();

                if ($payment && $payment->status !== 'paid') {
                    $payment->update([
                        'status' => 'paid',
                        'gateway_payment_id' => $paymentIntent,
                    ]);

                    $shouldDispatchSuccess = true;
                    $lockedPayment = $payment->fresh();
                }
            });

            if ($shouldDispatchSuccess && $lockedPayment) {
                event(new PaymentSucceeded($lockedPayment));
            }

            return true;
        } catch (Throwable $e) {
            report($e);
            return false;
        }
    }

    public function handleWebhook(string $payload, array $headers = []): bool
    {
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

        return match ($event->type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->completeCheckout($event->data->object),
            'checkout.session.async_payment_failed' => $this->failCheckout($event->data->object),
            default => true,
        };
    }

    protected function completeCheckout($session): bool
    {
        if (($session->payment_status ?? null) !== 'paid') {
            return true;
        }

        $paymentIntent = is_string($session->payment_intent ?? null)
            ? $session->payment_intent
            : null;

        $shouldDispatchSuccess = false;
        $lockedPayment = null;

        DB::transaction(function () use ($session, $paymentIntent, &$shouldDispatchSuccess, &$lockedPayment) {
            $payment = Payment::query()
                ->where('gateway_order_id', $session->id)
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->status !== 'paid') {
                $payment->update([
                    'status' => 'paid',
                    'gateway_payment_id' => $paymentIntent,
                ]);

                $shouldDispatchSuccess = true;
                $lockedPayment = $payment->fresh();
            }
        });

        if ($shouldDispatchSuccess && $lockedPayment) {
            event(new PaymentSucceeded($lockedPayment));
        }

        return true;
    }

    protected function failCheckout($session): bool
    {
        $shouldDispatchFailed = false;
        $lockedPayment = null;

        DB::transaction(function () use ($session, &$shouldDispatchFailed, &$lockedPayment) {
            $payment = Payment::query()
                ->where('gateway_order_id', $session->id)
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->status !== 'paid') {
                $payment->update([
                    'status' => 'failed',
                ]);

                $shouldDispatchFailed = true;
                $lockedPayment = $payment->fresh();
            }
        });

        if ($shouldDispatchFailed && $lockedPayment) {
            event(new PaymentFailed($lockedPayment));
        }

        return true;
    }

    public function refund(
        string $paymentId,
        ?float $amount = null,
        string $currency = 'USD'
    ): array {
        $stripe = $this->stripe();

        $data = [
            'payment_intent' => $paymentId,
        ];

        if ($amount !== null) {
            $data['amount'] = $this->toMinorUnits($amount, $currency);
        }

        $refund = $stripe->refunds->create($data);

        return $refund->toArray();
    }
}