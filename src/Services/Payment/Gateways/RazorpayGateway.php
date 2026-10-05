<?php

namespace Tekwing\MultiPay\Services\Gateways;

use Tekwing\MultiPay\Contracts\PaymentGatewayInterface;
use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\PaymentGateway;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Events\PaymentSucceeded;
use Tekwing\MultiPay\Events\PaymentFailed;
use Razorpay\Api\Api;
use Exception;

class RazorpayGateway implements PaymentGatewayInterface
{
    protected function gateway(): PaymentGateway
    {
        return PaymentGateway::where('code', 'razorpay')
            ->where('status', true)
            ->firstOrFail();
    }

    protected function api(): Api
    {
        $gateway = $this->gateway();
        $credentials = $gateway->credentials;

        return new Api(
            $credentials['key_id'],
            $credentials['key_secret']
        );
    }

    public function initiate(Payable $payable): array
    {
        $api = $this->api();
        
        $amount = $payable->getPayableAmount();
        $description = $payable->getPayableDescription();

        $razorpayOrder = $api->order->create([
            // Razorpay limits 'receipt' to 40 characters max
            'receipt' => substr($description, 0, 40),
            'amount' => (int) round($amount * 100),
            'currency' => 'INR',
        ]);

        // INSERT SEPARATE PAYMENT RECORD (Polymorphic)
        $payable->payments()->create([
            'gateway' => 'razorpay',
            'gateway_order_id' => $razorpayOrder['id'],
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $gateway = $this->gateway();

        return [
            'gateway' => 'razorpay',
            'key' => $gateway->credentials['key_id'],
            'order_id' => $razorpayOrder['id'],
            'amount' => (int) round($amount * 100),
            'currency' => 'INR',
            'name' => config('app.name'),
            'description' => $description,
            'prefill' => [
                'name' => auth()->user()->name ?? '',
                'email' => auth()->user()->email ?? '',
            ],
        ];
    }

    public function verify(array $data): bool
    {
        try {
            $api = $this->api();

            // 1. Verify the signature with Razorpay
            $api->utility->verifyPaymentSignature([
                'razorpay_order_id'   => $data['razorpay_order_id'],
                'razorpay_payment_id' => $data['razorpay_payment_id'],
                'razorpay_signature'  => $data['razorpay_signature'],
            ]);

            // 2. Look up the specific PAYMENT attempt
            $payment = Payment::where('gateway_order_id', $data['razorpay_order_id'])->first();

            if ($payment && $payment->status !== 'paid') {
                
                // 3. Update the package's Payment record
                $payment->update([
                    'status'             => 'paid',
                    'gateway_payment_id' => $data['razorpay_payment_id'],
                ]);

                // 4. Fire the Success Event for the Host App!
                event(new PaymentSucceeded($payment));
            }

            return true;

        } catch (Exception $e) {
            
            // If we know which payment failed, we could optionally mark it and fire PaymentFailed
            if (isset($data['razorpay_order_id'])) {
                $payment = Payment::where('gateway_order_id', $data['razorpay_order_id'])->first();
                if ($payment && $payment->status !== 'paid') {
                    $payment->update(['status' => 'failed']);
                    event(new PaymentFailed($payment));
                }
            }

            report($e);
            return false;
        }
    }

    public function refund(
        string $paymentId,
        ?float $amount = null
    ): array {

        $api = $this->api();
        $data = [];

        if ($amount !== null) {
            $data['amount'] = (int) round($amount * 100);
        }

        $refund = $api
            ->payment($paymentId)
            ->refund($data);

        return $refund->toArray();
    }

    public function handleWebhook(
        string $payload,
        array $headers = []
    ): bool {
        // When you implement Razorpay webhooks (e.g. order.paid event),
        // make sure you use Payment::where('gateway_order_id', ...) 
        // to update the status and fire event(new PaymentSucceeded($payment));
        // exactly like Stripe's webhook handler!

        return true;
    }
}