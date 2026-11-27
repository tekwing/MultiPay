<?php

namespace Tekwing\MultiPay\Services\Payment\Gateways;

use Exception;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Api;
use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Contracts\PaymentGatewayInterface;
use Tekwing\MultiPay\Events\PaymentFailed;
use Tekwing\MultiPay\Events\PaymentSucceeded;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Models\PaymentGateway;

class RazorpayGateway implements PaymentGatewayInterface
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
            ->where('code', 'razorpay')
            ->where('status', true)
            ->firstOrFail();

        return $this->gatewayModel;
    }

    protected function api(?PaymentGateway $gateway = null): Api
    {
        $gateway = $gateway ?? $this->gateway();
        $credentials = $gateway->credentials ?? [];

        if (empty($credentials['key_id']) || empty($credentials['key_secret'])) {
            throw new Exception('Razorpay API credentials are not configured.');
        }

        return new Api(
            $credentials['key_id'],
            $credentials['key_secret']
        );
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
        $api = $this->api($gateway);

        $amount = $payable->getPayableAmount();
        $currency = strtoupper(trim($payable->getPayableCurrency() ?: 'INR'));
        $description = $payable->getPayableDescription();
        $minorAmount = $this->toMinorUnits($amount, $currency);

        $razorpayOrder = $api->order->create([
            'receipt' => substr((string) ($payment->id . '-' . $description), 0, 40),
            'amount' => $minorAmount,
            'currency' => $currency,
        ]);

        $payment->update([
            'gateway_order_id' => $razorpayOrder['id'],
        ]);

        $user = auth()->user();

        return [
            'gateway' => 'razorpay',
            'key' => $gateway->credentials['key_id'],
            'order_id' => $razorpayOrder['id'],
            'amount' => $minorAmount,
            'currency' => $currency,
            'name' => config('app.name'),
            'description' => $description,
            'prefill' => [
                'name' => $user?->name ?? '',
                'email' => $user?->email ?? '',
            ],
        ];
    }

    public function verify(array $data): bool
    {
        try {
            $api = $this->api();

            $api->utility->verifyPaymentSignature([
                'razorpay_order_id' => $data['razorpay_order_id'],
                'razorpay_payment_id' => $data['razorpay_payment_id'],
                'razorpay_signature' => $data['razorpay_signature'],
            ]);

            $shouldDispatchSuccess = false;
            $lockedPayment = null;

            DB::transaction(function () use ($data, &$shouldDispatchSuccess, &$lockedPayment) {
                $payment = Payment::query()
                    ->where('gateway_order_id', $data['razorpay_order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($payment && $payment->status !== 'paid') {
                    $payment->update([
                        'status' => 'paid',
                        'gateway_payment_id' => $data['razorpay_payment_id'],
                    ]);

                    $shouldDispatchSuccess = true;
                    $lockedPayment = $payment->fresh();
                }
            });

            if ($shouldDispatchSuccess && $lockedPayment) {
                event(new PaymentSucceeded($lockedPayment));
            }

            return true;
        } catch (Exception $e) {
            if (!empty($data['razorpay_order_id'])) {
                $shouldDispatchFailed = false;
                $lockedPayment = null;

                DB::transaction(function () use ($data, &$shouldDispatchFailed, &$lockedPayment) {
                    $payment = Payment::query()
                        ->where('gateway_order_id', $data['razorpay_order_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($payment && $payment->status !== 'paid') {
                        $payment->update(['status' => 'failed']);
                        $shouldDispatchFailed = true;
                        $lockedPayment = $payment->fresh();
                    }
                });

                if ($shouldDispatchFailed && $lockedPayment) {
                    event(new PaymentFailed($lockedPayment));
                }
            }

            report($e);
            return false;
        }
    }

    public function refund(
        string $paymentId,
        ?float $amount = null,
        string $currency = 'INR'
    ): array {
        $api = $this->api();
        $data = [];

        if ($amount !== null) {
            $data['amount'] = $this->toMinorUnits($amount, $currency);
        }

        $refund = $api
            ->payment($paymentId)
            ->refund($data);

        return $refund->toArray();
    }

    public function handleWebhook(string $payload, array $headers = []): bool
    {
        $gateway = $this->gateway();
        $webhookSecret = $gateway->credentials['webhook_secret'] ?? null;

        if (!$webhookSecret) {
            return false;
        }

        $signature = $headers['X-Razorpay-Signature']
            ?? $headers['x-razorpay-signature']
            ?? null;

        if (!$signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return false;
        }

        $eventName = $event['event'] ?? '';
        $orderId = $event['payload']['payment']['entity']['order_id']
            ?? $event['payload']['order']['entity']['id']
            ?? null;
        $gatewayPaymentId = $event['payload']['payment']['entity']['id'] ?? null;

        if (!$orderId) {
            return true;
        }

        if (in_array($eventName, ['order.paid', 'payment.captured'], true)) {
            $shouldDispatchSuccess = false;
            $lockedPayment = null;

            DB::transaction(function () use ($orderId, $gatewayPaymentId, &$shouldDispatchSuccess, &$lockedPayment) {
                $payment = Payment::query()
                    ->where('gateway_order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if ($payment && $payment->status !== 'paid') {
                    $payment->update([
                        'status' => 'paid',
                        'gateway_payment_id' => $gatewayPaymentId ?? $payment->gateway_payment_id,
                    ]);

                    $shouldDispatchSuccess = true;
                    $lockedPayment = $payment->fresh();
                }
            });

            if ($shouldDispatchSuccess && $lockedPayment) {
                event(new PaymentSucceeded($lockedPayment));
            }
        }

        return true;
    }
}