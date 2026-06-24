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
            $table->uuid('payment_id')->primary();
            $table->foreignUuid('invoice_id')
                  ->nullable()
                  ->constrained('invoices', 'invoice_id')
                  ->nullOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid('transaction_id')
                  ->nullable()
                  ->comment('For POS transactions')
                  ->constrained('transactions', 'transaction_id')
                  ->nullOnDelete()
                  ->cascadeOnUpdate();
            $table->string('payment_reference')->unique();
            $table->decimal('amount', 12, 2);
            $table->datetime('payment_date');
            $table->enum('payment_type', ['subscription', 'pos_transaction'])
                  ->default('subscription');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'refunded'])
                  ->default('pending');
            $table->string('gateway_transaction_id')->nullable();
            $table->text('gateway_response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('invoice_id');
            $table->index('transaction_id');
            $table->index('payment_reference');
            $table->index('status');
            $table->index('payment_type');
            $table->index('payment_date');
            $table->index(['status', 'payment_date']);
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
