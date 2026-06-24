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
        Schema::create('gateway_branches', function (Blueprint $table) {
            $table->uuid('branch_mapping_id')->primary();
            
            $table->foreignUuid('gateway_id')
                  ->constrained('payment_getways', 'gateway_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            
            $table->string('branch_identifier');  // Merchant ID, Account ID
            $table->string('branch_name');
            $table->string('country');
            
            $table->boolean('is_active')->default(true);
            
            // Indexes
            $table->index('gateway_id');
            $table->index(['gateway_id', 'country']);
            $table->index(['gateway_id', 'is_active']);
            
            // Unique constraint: មួយ branch_identifier ក្នុងមួយ gateway
            $table->unique(['gateway_id', 'branch_identifier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway_branches');
    }
};
