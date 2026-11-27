<!-- package/resources/views/demo-checkout.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MultiPay Demo Checkout</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 50px; background: #f3f4f6; text-align: center; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); max-width: 400px; margin: 0 auto; }
        .input-group { margin-bottom: 20px; text-align: left; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #374151; }
        input, select { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; margin-top: 4px; }
        button { background: #4f46e5; color: white; border: none; padding: 14px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; width: 100%; transition: 0.2s; }
        button:hover { background: #4338ca; }
        button:disabled { background: #9ca3af; cursor: not-allowed; }
    </style>
    
    <!-- 1. Include Gateway SDKs -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body>
    <div class="card">
        <h2>MultiPay Checkout</h2>
        <p style="color: #6b7280; font-size: 14px;">Test your gateway integration instantly.</p>
        
        <form id="paymentForm">
            <!-- CSRF token for Laravel security -->
            <input type="hidden" id="csrfToken" value="{{ csrf_token() }}">
            
            <div class="input-group">
                <label for="orderId">Local Order ID (Your DB)</label>
                <input type="number" id="orderId" value="1001" required>
            </div>

            <div class="input-group">
                <label for="gatewaySelect">Choose Gateway</label>
                <select id="gatewaySelect">
                    <option value="razorpay">Razorpay</option>
                    <option value="stripe">Stripe</option>
                </select>
            </div>
            
            <button type="submit" id="payButton">Pay ₹500.00 Now</button>
        </form>
    </div>

    <!-- 2. The JavaScript logic to handle the payment flow -->
    <script>
        document.getElementById('paymentForm').addEventListener('submit', async function(e) {
            e.preventDefault(); 
            
            const button = document.getElementById('payButton');
            const gateway = document.getElementById('gatewaySelect').value;
            const orderId = document.getElementById('orderId').value;
            const csrfToken = document.getElementById('csrfToken').value;
            
            button.disabled = true;
            button.innerText = 'Connecting to Gateway...';

            try {
                // Step 1: Send the Local Order ID to your package backend
                const response = await fetch('/multipay/demo/pay', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken 
                    },
                    body: JSON.stringify({ 
                        gateway: gateway,
                        order_id: orderId // Passing the ID from the form
                    })
                });
                
                const data = await response.json();

                if (!response.ok) throw new Error(data.message || 'Server error');

                // Step 2: Handle Gateway Specific Frontend Logic
                if (gateway === 'razorpay') {
                    // Open Razorpay Modal using the Gateway Order ID returned from backend
                    var options = {
                        "key": data.gateway_key,
                        "amount": 50000, // ₹500.00 in paise
                        "currency": "INR",
                        "name": "Your Awesome App",
                        "description": "Payment for Order #" + orderId,
                        "order_id": data.gateway_response.id, // The Razorpay specific Order ID
                        "handler": function (rzp_response){
                            alert('Success! Razorpay Payment ID: ' + rzp_response.razorpay_payment_id);
                            button.innerText = 'Payment Complete!';
                        }
                    };
                    var rzp = new Razorpay(options);
                    rzp.on('payment.failed', function (rzp_response){
                        alert('Payment Failed: ' + rzp_response.error.description);
                        button.innerText = 'Pay ₹500.00 Now';
                        button.disabled = false;
                    });
                    rzp.open();
                } 
                else if (gateway === 'stripe') {
                    // Stripe usually returns a Checkout Session URL to redirect the user to
                    if (data.gateway_response.url) {
                        window.location.href = data.gateway_response.url;
                    } else {
                        alert('Stripe URL missing from response.');
                        button.innerText = 'Pay ₹500.00 Now';
                        button.disabled = false;
                    }
                }
            } catch (error) {
                console.error(error);
                alert('Error: ' + error.message);
                button.innerText = 'Pay ₹500.00 Now';
                button.disabled = false;
            }
        });
    </script>
</body>
</html>