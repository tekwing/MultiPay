<?php

namespace Tekwing\MultiPay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Tekwing\MultiPay\Models\Payment;

class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Payment $payment) {}
}