<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            
            $table->string('gateway'); // 'stripe', 'razorpay'
            
            // The ID generated when initiating checkout (Stripe Session ID, Razorpay Order ID)
            $table->string('gateway_order_id')->nullable(); 
            
            // The final transaction ID after successful payment (Stripe PaymentIntent ID, Razorpay Payment ID)
            $table->string('gateway_payment_id')->nullable(); 
            
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, paid, failed, refunded
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
