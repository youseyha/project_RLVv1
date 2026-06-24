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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('method_id')->primary();
            
            $table->foreignUuid('tenant_id')
                  ->constrained('tenants', 'tenant_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            
            $table->string('method_name');
            $table->enum('method_type', ['credit_card', 'bank_transfer', 'e_wallet', 'cash'])
                  ->default('cash');
            
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
