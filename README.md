MultiPay for Laravel

A flexible and reusable payment gateway manager for Laravel with support for Stripe and Razorpay.

MultiPay uses a polymorphic architecture, allowing any model in your application—such as Orders, Invoices, Carts, or Subscriptions—to seamlessly process payments through dynamically managed, database-driven gateway credentials.

Requirements

PHP 8.1 or higher

Laravel 10.0, 11.0, or 12.0

Installation

Install the package via Composer:

composer require tekwing/multipay

Publish Configuration

Publish the MultiPay configuration file:

php artisan vendor:publish --tag=payment-config

Publish Database Migrations

Publish the payment-related migrations:

php artisan vendor:publish --tag=payment-migrations

Run Migrations

Run the migrations to create the payment_gateways and payments tables:

php artisan migrate

Configuration

The configuration file is located at:

config/payment.php


You can configure the default payment gateway using the PAYMENT_GATEWAY environment variable.

PAYMENT_GATEWAY=stripe


If no gateway is explicitly provided when initiating a payment, MultiPay will use the configured default gateway.

Setup & Usage
1. Configure a Payment Gateway

MultiPay loads payment gateway credentials dynamically from the database. This allows you to manage active gateways through your application's admin panel without hard-coding gateway configuration.

For example, you can create a Stripe gateway using the PaymentGateway model:

use Tekwing\MultiPay\Models\PaymentGateway;

PaymentGateway::create([
    'name' => 'Stripe',
    'code' => 'stripe',
    'credentials' => [
        'secret_key' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
    'is_active' => true,
    'status' => true,
]);


The gateway credentials are stored in the payment_gateways table and can be managed dynamically by your application.

2. Prepare Your Payable Models

Any model that needs to support payments must implement the Tekwing\MultiPay\Contracts\Payable interface.

For example, an Order model:

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\Payment;

class Order extends Model implements Payable
{
    /**
     * Define the polymorphic relationship
     * to the package's payments table.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Return the total amount to be charged.
     */
    public function getPayableAmount(): float
    {
        return (float) $this->total_amount;
    }

    /**
     * Return a description for the payment gateway.
     */
    public function getPayableDescription(): string
    {
        return "Payment for Order #{$this->id}";
    }
}


The same approach can be used for other models such as:

Order

Invoice

Cart

Subscription

Any other Eloquent model that requires payment processing

3. Initiate a Payment

Inject PaymentService into your controller, service, or route and initiate the payment.

MultiPay will:

Resolve the requested payment gateway.

Retrieve the gateway credentials from the database.

Calculate the payable amount using your model.

Create a pending payment record.

Initiate the transaction through the selected gateway.

Example:

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Tekwing\MultiPay\Services\PaymentService;

class CheckoutController extends Controller
{
    public function pay(
        Request $request,
        PaymentService $paymentService
    ) {
        $order = Order::findOrFail($request->order_id);

        // Initiate payment using Stripe.
        //
        // If the second argument is omitted,
        // config('payment.default') will be used.
        $response = $paymentService->initiate($order, 'stripe');

        return response()->json([
            'success' => true,
            'gateway_response' => $response,
        ]);
    }
}

Using the Default Gateway

If you don't specify a gateway, MultiPay uses the gateway configured in config/payment.php:

$response = $paymentService->initiate($order);


With:

PAYMENT_GATEWAY=stripe


MultiPay will automatically use Stripe.

Supported Payment Gateways

MultiPay currently supports:

Stripe

Razorpay

Gateway credentials are stored in the database, making it possible to enable, disable, or switch gateways dynamically.

Database Architecture

MultiPay uses two primary tables:

payment_gateways

Stores payment gateway configuration and credentials.

Typical fields include:

id
name
code
credentials
is_active
status
timestamps

payments

Stores payment transactions associated with your application's payable models.

The payment relationship is polymorphic:

Payment
   │
   └── payable
       ├── Order
       ├── Invoice
       ├── Subscription
       └── Any Payable Model


This allows a single payment system to work across multiple models without requiring separate payment implementations.

Webhooks

Coming Soon

Webhook handling is planned for a future release.

Once webhook support is available, gateway webhook events will be used to update the corresponding payment records when asynchronous events occur, such as:

Payment completed

Payment failed

Payment refunded

Until then, you should implement the appropriate webhook listeners in your application to keep the local payments table synchronized with your payment provider.

Example Workflow

A typical MultiPay workflow looks like this:

Customer
   │
   ▼
Checkout
   │
   ▼
Payable Model (Order / Invoice / Subscription)
   │
   ▼
PaymentService
   │
   ├── Resolve Gateway
   │
   ├── Load Database Credentials
   │
   ├── Calculate Payable Amount
   │
   ├── Create Pending Payment
   │
   ▼
Stripe / Razorpay
   │
   ▼
Payment Response

License

This package is open-sourced software. Please refer to the project's license file for licensing details.