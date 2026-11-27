<?php
// app/Http/Controllers/CheckoutController.php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Tekwing\MultiPay\Models\PaymentGateway;
use Tekwing\MultiPay\Services\PaymentService;

class CheckoutController extends Controller
{
    // Step 1: Show the Checkout Page
    public function showCheckout($orderId)
    {
        $order = Order::findOrFail($orderId);
        
        // Fetch only enabled gateways from the database
        $activeGateways = PaymentGateway::where('is_active', true)->get();

        return view('checkout', [
            'order' => $order,
            'gateways' => $activeGateways
        ]);
    }

    // Step 2: Handle the AJAX Request from the frontend
    public function processPayment(Request $request, PaymentService $paymentService)
    {
        $order = Order::findOrFail($request->order_id);
        
        // Pass the Order model and the chosen gateway to your package
        $gatewayResponse = $paymentService->initiate($order, $request->gateway_code);

        return response()->json($gatewayResponse);
    }
}