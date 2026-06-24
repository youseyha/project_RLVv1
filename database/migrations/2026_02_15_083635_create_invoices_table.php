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
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('invoice_id')->primary();
            
            $table->foreignUuid('tenant_id')
                  ->constrained('tenants', 'tenant_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid('subscription_id')
                  ->constrained('subscriptions', 'subscription_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->string('invoice_number')->unique();
            
            $table->date('invoice_date');
            $table->date('due_date');//ថ្ងៃកំណត់បង់ប្រាក់
            // Amounts
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'cancelled','pending'])
                  ->default('draft');
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('tenant_id');
            $table->index('subscription_id');
            $table->index('invoice_number');
            $table->index('status');
            $table->index('due_date');
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
