<?php

namespace Tekwing\MultiPay\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Payable
{
    public function payments(): MorphMany;
    public function getPayableAmount(): float;
    public function getPayableDescription(): string;
}