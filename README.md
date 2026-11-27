# MultiPay for Laravel (`tekwing/multipay`)

MultiPay is a Laravel payment gateway package that lets you support **Razorpay** and **Stripe** from a single unified interface. Admins can toggle payment gateways on or off dynamically from the database, and your checkout automatically displays only the active gateways.

---

## Features

* **Unified Payment API:** Initiate payments, verify signatures/sessions, handle webhooks, and issue refunds through a single `PaymentService`.
* **Dynamic Gateway Toggling:** Enable or disable Razorpay and Stripe in the `payment_gateways` table (`is_active` and `status`)—only active gateways appear at checkout.
* **Polymorphic `Payable` Support:** Attach payments to any Eloquent model (`Order`, `Invoice`, `Subscription`, etc.) via `morphMany`.
* **Dynamic Currency & Minor-Unit Conversion:** Automatically converts amounts to paise/cents (while respecting zero-decimal currencies like `JPY` and `KRW`).
* **Idempotent Verification & Webhooks:** Uses database transactions and row-level locking (`lockForUpdate`) to prevent duplicate payment processing across browser redirects and asynchronous webhooks.
* **Laravel Events:** Dispatches `PaymentSucceeded` and `PaymentFailed` events so your application can update order statuses cleanly.

---

## Requirements

* PHP `^8.1`
* Laravel `^10.0` | `^11.0` | `^12.0`
* `razorpay/razorpay`
* `stripe/stripe-php`

---

## Step-by-Step Integration Guide (From Scratch)

Follow these steps in order to integrate MultiPay into any Laravel project.

### Step 1: Install the Package via Composer

Run the following command in your Laravel project root:

```bash
composer require tekwing/multipay
```

Laravel will automatically discover and register `Tekwing\MultiPay\Providers\PaymentGatewayServiceProvider`.

---

### Step 2: Run Package Migrations

Run migrations to create the `payment_gateways` and `payments` tables:

```bash
php artisan migrate
```

*(Optional)* Publish the config or demo views if you want to customize them:

```bash
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=payment-views
```

---

### Step 3: Exclude Webhook URLs from CSRF Protection

Razorpay and Stripe send `POST` requests to your server without a Laravel CSRF token. Exclude `payment/webhook/*` so Laravel does not reject them with a `419 Page Expired` error.

**For Laravel 11 & 12 (`bootstrap/app.php`):**

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'payment/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

**For Laravel 10 (`app/Http/Middleware/VerifyCsrfToken.php`):**

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    protected $except = [
        'payment/webhook/*',
    ];
}
```

---

### Step 4: Add Gateway Credentials in `.env` and Database

Add your sandbox/live API keys to your `.env` file:

```env
PAYMENT_GATEWAY=stripe

RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxxxx
RAZORPAY_WEBHOOK_SECRET=whsec_xxxxxxxxxxxx

STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxx
```

Next, create a seeder (`database/seeders/PaymentGatewaySeeder.php`) or use your admin panel to insert `razorpay` and `stripe` into the `payment_gateways` table:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tekwing\MultiPay\Models\PaymentGateway;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        PaymentGateway::updateOrCreate(
            ['code' => 'razorpay'],
            [
                'name' => 'Razorpay',
                'credentials' => [
                    'key_id' => env('RAZORPAY_KEY_ID'),
                    'key_secret' => env('RAZORPAY_KEY_SECRET'),
                    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
                ],
                'is_active' => true, // Toggle true/false from Admin to show/hide on checkout
                'status' => true,
            ]
        );

        PaymentGateway::updateOrCreate(
            ['code' => 'stripe'],
            [
                'name' => 'Stripe',
                'credentials' => [
                    'secret_key' => env('STRIPE_SECRET_KEY'),
                    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
                ],
                'is_active' => true, // Toggle true/false from Admin to show/hide on checkout
                'status' => true,
            ]
        );
    }
}
```

Run the seeder:

```bash
php artisan db:seed --class=PaymentGatewaySeeder
```

---

### Step 5: Prepare Your `orders` and `order_items` Tables & Models

Make sure your `Order` model implements `Tekwing\MultiPay\Contracts\Payable`. This tells MultiPay how to fetch the payment amount, currency, description, and polymorphic `payments()` relationship.

**`app/Models/Order.php`:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Tekwing\MultiPay\Contracts\Payable;
use Tekwing\MultiPay\Models\Payment;

class Order extends Model implements Payable
{
    protected $fillable = [
        'user_id',
        'currency',
        'total_amount',
        'status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function getPayableAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function getPayableCurrency(): string
    {
        return strtoupper((string) ($this->currency ?: 'INR'));
    }

    public function getPayableDescription(): string
    {
        return "Order #{$this->id}";
    }
}
```

**`app/Models/OrderItem.php`:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'unit_price',
        'quantity',
        'subtotal',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

---

### Step 6: Create the Checkout Controller (Save Order + Initiate Payment)

In your `CheckoutController`:
1. **`show()`** fetches only the gateways turned ON by the admin via `$manager->enabledGateways()`.
2. **`checkout()`** saves the `orders` and `order_items` records inside a database transaction, then calls `$paymentService->initiate($order, $gatewayCode)` and returns the JSON payload to the frontend.

**`app/Http/Controllers/CheckoutController.php`:**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tekwing\MultiPay\Services\Payment\PaymentGatewayManager;
use Tekwing\MultiPay\Services\Payment\PaymentService;

class CheckoutController extends Controller
{
    // 1. Show checkout page with only admin-enabled gateways
    public function show(PaymentGatewayManager $manager)
    {
        $gateways = $manager->enabledGateways();

        return view('checkout', compact('gateways'));
    }

    // 2. Save order + order items, then initiate payment with chosen gateway
    public function checkout(Request $request, PaymentService $paymentService): JsonResponse
    {
        $validated = $request->validate([
            'gateway_code' => ['required', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['required', 'numeric', 'min:0.01'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        // Save order & order items safely inside a transaction
        $order = DB::transaction(function () use ($validated, $request) {
            $totalAmount = collect($validated['items'])->sum(
                fn (array $item) => (float) $item['price'] * (int) $item['quantity']
            );

            $order = Order::create([
                'user_id' => $request->user()?->id,
                'currency' => strtoupper($validated['currency'] ?? 'INR'),
                'total_amount' => $totalAmount,
                'status' => 'pending_payment',
            ]);

            foreach ($validated['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $item['name'],
                    'unit_price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => (float) $item['price'] * (int) $item['quantity'],
                ]);
            }

            return $order;
        });

        // Create payment session/order on the chosen gateway
        $paymentData = $paymentService->initiate(
            $order,
            $validated['gateway_code']
        );

        return response()->json([
            'order_id' => $order->id,
            'payment' => $paymentData,
        ]);
    }
}
```

---

### Step 7: Create the Payment Callback Controller (Verify & Redirect)

Create `PaymentCallbackController` in your host app to handle:
* **Razorpay verification** after the user finishes paying in the Razorpay popup.
* **Stripe return redirect** (`payment.success` and `payment.cancel`).

**`app/Http/Controllers/PaymentCallbackController.php`:**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Services\Payment\PaymentService;

class PaymentCallbackController extends Controller
{
    /**
     * Called via AJAX after Razorpay checkout popup succeeds.
     */
    public function verifyRazorpay(Request $request, PaymentService $paymentService): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $payment = Payment::query()
            ->with('payable')
            ->where('gateway_order_id', $validated['razorpay_order_id'])
            ->firstOrFail();

        $verified = $paymentService->verify($payment, $validated);

        if (!$verified) {
            return response()->json([
                'success' => false,
                'message' => 'Payment signature verification failed.',
            ], 422);
        }

        $payment->refresh();
        $this->markPayablePaid($payment);

        return response()->json([
            'success' => true,
            'redirect' => route('payment.success', ['payment_id' => $payment->id]),
        ]);
    }

    /**
     * Stripe redirects here after checkout completes.
     */
    public function stripeSuccess(Request $request, PaymentService $paymentService)
    {
        $sessionId = $request->query('session_id');
        $paymentId = $request->query('payment_id');
        $payment = null;

        if ($sessionId) {
            $payment = Payment::query()
                ->with('payable')
                ->where('gateway_order_id', $sessionId)
                ->first();

            if ($payment) {
                $paymentService->verify($payment, [
                    'session_id' => $sessionId,
                ]);

                $payment->refresh();
                $this->markPayablePaid($payment);
            }
        } elseif ($paymentId) {
            $payment = Payment::query()
                ->with('payable')
                ->find($paymentId);
        }

        return view('payment.success', [
            'payment' => $payment,
            'payable' => $payment?->payable,
        ]);
    }

    /**
     * User cancelled or closed checkout.
     */
    public function cancel()
    {
        return view('payment.cancel');
    }

    /**
     * Idempotently mark the linked payable model (e.g., Order) as paid.
     */
    protected function markPayablePaid(Payment $payment): void
    {
        if ($payment->status !== 'paid') {
            return;
        }

        $payable = $payment->payable;

        if ($payable && $payable->status !== 'paid') {
            $payable->update([
                'status' => 'paid',
            ]);
        }
    }
}
```

---

### Step 8: Register Your Host App Routes (`routes/web.php`)

Add the checkout and callback routes in `routes/web.php`. Keep the route names `payment.success` and `payment.cancel` exact because Stripe uses them for `success_url` and `cancel_url`.

```php
<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentCallbackController;
use Illuminate\Support\Facades\Route;

// Checkout routes
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'checkout'])->name('checkout.submit');

// Payment callback & return routes
Route::post('/payments/razorpay/verify', [PaymentCallbackController::class, 'verifyRazorpay'])
    ->name('payment.razorpay.verify');

Route::get('/payment/success', [PaymentCallbackController::class, 'stripeSuccess'])
    ->name('payment.success');

Route::get('/payment/cancel', [PaymentCallbackController::class, 'cancel'])
    ->name('payment.cancel');
```

---

### Step 9: Build the Checkout Blade & Frontend JavaScript

On the frontend:
1. Render radio buttons for `$gateways` (automatically shows only what is enabled in `payment_gateways`).
2. Submit the checkout form via `fetch()`.
3. Check `data.payment.gateway`:
   * If `'stripe'`, redirect to `data.payment.checkout_url`.
   * If `'razorpay'`, open the `Razorpay` modal and POST the response to `/payments/razorpay/verify`.

**`resources/views/checkout.blade.php`:**

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Checkout</title>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
    <h2>Checkout</h2>

    @if($gateways->isEmpty())
        <p>No payment gateways are currently enabled.</p>
    @else
        <form id="checkout-form" action="{{ route('checkout.submit') }}" method="POST">
            @csrf

            <h4>Select Payment Method:</h4>
            @foreach($gateways as $gateway)
                <label style="display: block; margin-bottom: 8px;">
                    <input
                        type="radio"
                        name="gateway_code"
                        value="{{ $gateway->code }}"
                        {{ $loop->first ? 'checked' : '' }}
                    >
                    {{ $gateway->name }}
                </label>
            @endforeach

            <button type="submit">Place Order & Pay</button>
        </form>
    @endif

    <script>
    document.getElementById('checkout-form')?.addEventListener('submit', async function (e) {
        e.preventDefault();

        const gatewayCode = document.querySelector('input[name="gateway_code"]:checked')?.value;

        // Example cart payload (replace with your dynamic cart items)
        const payload = {
            gateway_code: gatewayCode,
            currency: 'INR',
            items: [
                { product_id: 1, name: 'Sample Product', price: 499.00, quantity: 1 }
            ]
        };

        const response = await fetch(this.action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!response.ok) {
            alert(data.message || 'Unable to create order.');
            return;
        }

        const payment = data.payment;

        // 1. Handle Stripe Checkout Redirect
        if (payment.gateway === 'stripe' && payment.checkout_url) {
            window.location.href = payment.checkout_url;
            return;
        }

        // 2. Handle Razorpay Modal
        if (payment.gateway === 'razorpay') {
            const options = {
                key: payment.key,
                amount: payment.amount,
                currency: payment.currency,
                name: payment.name,
                description: payment.description,
                order_id: payment.order_id,
                prefill: payment.prefill || {},
                handler: async function (rzpResponse) {
                    const verifyRes = await fetch("{{ route('payment.razorpay.verify') }}", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            razorpay_order_id: rzpResponse.razorpay_order_id,
                            razorpay_payment_id: rzpResponse.razorpay_payment_id,
                            razorpay_signature: rzpResponse.razorpay_signature
                        })
                    });

                    const verifyData = await verifyRes.json();

                    if (verifyRes.ok && verifyData.success) {
                        window.location.href = verifyData.redirect;
                    } else {
                        alert(verifyData.message || 'Payment verification failed.');
                    }
                },
                modal: {
                    ondismiss: function () {
                        window.location.href = "{{ route('payment.cancel') }}";
                    }
                }
            };

            const rzp = new Razorpay(options);
            rzp.open();
        }
    });
    </script>
</body>
</html>
```

Also create two simple Blade views for the return pages:
* `resources/views/payment/success.blade.php` — *"Payment Successful! Thank you for your order."*
* `resources/views/payment/cancel.blade.php` — *"Payment Cancelled. You can try again from checkout."*

---

### Step 10: Listen to `PaymentSucceeded` and `PaymentFailed` Events

Never mark an `Order` as paid inside the checkout creation step. Instead, listen to MultiPay's `PaymentSucceeded` and `PaymentFailed` events so your `orders` table updates automatically whether the payment is confirmed via browser callback or webhook.

**`app/Providers/AppServiceProvider.php`:**

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Tekwing\MultiPay\Events\PaymentFailed;
use Tekwing\MultiPay\Events\PaymentSucceeded;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
            $order = $event->payment->payable;

            if ($order && $order->status !== 'paid') {
                $order->update(['status' => 'paid']);
            }
        });

        Event::listen(PaymentFailed::class, function (PaymentFailed $event) {
            $order = $event->payment->payable;

            if ($order && $order->status !== 'paid') {
                $order->update(['status' => 'failed']);
            }
        });
    }
}
```

---

### Step 11: Admin Gateway Toggle (Turn Gateways On/Off)

To let an admin enable or disable Razorpay or Stripe at any time, update `is_active` on `PaymentGateway`:

```php
use Illuminate\Http\Request;
use Tekwing\MultiPay\Models\PaymentGateway;

public function toggleGateway(Request $request, PaymentGateway $gateway)
{
    $gateway->update([
        'is_active' => $request->boolean('is_active'),
    ]);

    return back()->with('success', "{$gateway->name} updated.");
}
```

Once toggled off (`is_active = false`), that gateway immediately disappears from `$manager->enabledGateways()` on the checkout page and cannot be used for new checkouts.

---

## Webhooks Setup

MultiPay automatically registers the webhook endpoint:

```text
POST /payment/webhook/{gateway}
```

Add these webhook URLs in your gateway dashboards:
* **Stripe Webhook URL:** `https://your-domain.com/payment/webhook/stripe`
  * Events to enable: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`
* **Razorpay Webhook URL:** `https://your-domain.com/payment/webhook/razorpay`
  * Events to enable: `order.paid`, `payment.captured`

---

## Issuing Refunds

You can issue full or partial refunds for any paid `Payment` record:

```php
use Tekwing\MultiPay\Models\Payment;
use Tekwing\MultiPay\Services\Payment\PaymentService;

$payment = Payment::findOrFail($paymentId);

// Full refund
$refund = app(PaymentService::class)->refund($payment, $payment->gateway_payment_id);

// Partial refund (e.g., 150.00)
$partialRefund = app(PaymentService::class)->refund($payment, $payment->gateway_payment_id, 150.00);
```

---

## Database Tables Reference

### `payment_gateways`

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | `bigint` | Primary key |
| `name` | `string` | Display name (`Razorpay`, `Stripe`) |
| `code` | `string` | Unique driver code (`razorpay`, `stripe`) |
| `credentials` | `json` | API keys and webhook secrets |
| `is_active` | `boolean` | Admin toggle controlling checkout visibility |
| `status` | `boolean` | System availability flag |

### `payments`

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | `bigint` | Primary key |
| `payable_type` / `payable_id` | `morphs` | Polymorphic link to the host model (`Order`, etc.) |
| `gateway` | `string` | Gateway code (`razorpay`, `stripe`) |
| `gateway_order_id` | `string` | Razorpay Order ID or Stripe Checkout Session ID |
| `gateway_payment_id` | `string` | Razorpay Payment ID or Stripe Payment Intent ID |
| `amount` | `decimal(10,2)` | Payment amount |
| `status` | `string` | `pending`, `paid`, or `failed` |
| `metadata` | `json` | Extra metadata (including currency) |

---

## License

MIT