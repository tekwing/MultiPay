<?php

namespace Tekwing\MultiPay\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function payable()
    {
        return $this->morphTo();
    }
}