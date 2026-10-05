<?php

namespace Tekwing\MultiPay\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $fillable = [
        'name',
        'code',
        'credentials',
        'is_active',
        'status',
    ];

    protected $casts = [
        'credentials' => 'array',
        'is_active' => 'boolean',
        'status' => 'boolean',
    ];
}
