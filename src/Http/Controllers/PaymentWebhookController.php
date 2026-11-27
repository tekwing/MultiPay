<?php

namespace Tekwing\MultiPay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tekwing\MultiPay\Services\Payment\PaymentService;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    public function handle(Request $request, string $gateway): JsonResponse
    {
        $payload = $request->getContent();
        $headers = $request->headers->all();

        if ($payload === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Empty webhook payload.',
            ], 400);
        }

        try {
            $handled = $this->paymentService->handleWebhook(
                gatewayCode: $gateway,
                payload: $payload,
                headers: $headers
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Webhook processing failed.',
            ], 400);
        }

        if (!$handled) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook signature or payload.',
            ], 400);
        }

        return response()->json([
            'status' => 'ok',
        ], 200);
    }
}