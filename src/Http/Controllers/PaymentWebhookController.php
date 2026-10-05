<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment; // <-- Import the Payment model
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TestPaymentController extends Controller
{
    public function index()
    {
        $gateway = app(PaymentService::class)->activeGateway();

        return view('test-payment', compact('gateway'));
    }

    public function pay(Request $request)
    {
        // Validate the order exists
        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::findOrFail($request->order_id);

        // Initiate the payment
        $paymentResponse = app(PaymentService::class)->initiate($order);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'payment' => $paymentResponse,
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::findOrFail($request->order_id);

        // Pass the entire request data to the gateway driver
        $verified = app(PaymentService::class)->verify($order, $request->all());

        if (!$verified) {
            // Fallback status update if the driver didn't handle it
            if ($order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'failed']);
                
                // Also mark the latest pending payment attempt as failed
                $order->payments()
                    ->where('status', 'pending')
                    ->update(['status' => 'failed']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment successful.',
            'redirect' => route('payment.success'),
        ]);
    }

    public function success(Request $request)
    {
        // If Stripe redirects here with a session_id, verify it synchronously
        if ($request->has('session_id')) {
            
            // 1. Find the Payment record using the session_id
            $payment = Payment::with('order')
                ->where('gateway_order_id', $request->session_id)
                ->firstOrFail();
            
            // 2. Verify using the parent order
            app(PaymentService::class)->verify($payment->order, [
                'session_id' => $request->session_id
            ]);
        }

        return view('payment-success');
    }

    public function cancel()
    {
        return view('payment-cancel');
    }
}