<?php

namespace Tekwing\MultiPay\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    public function payable()
    {
        return $this->morphTo();
    }
}
