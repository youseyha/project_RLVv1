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
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->uuid('gateway_id')->primary();
            
            $table->string('gateway_name');
            $table->string('gateway_code')->unique();
            $table->string('api_endpoint');
            $table->text('api_credentials_encrypted');
            
            $table->decimal('transaction_fee_percentage', 5, 2)->default(0);//ភាគរយកម្រៃសេវា ឧទាហរណ៍: 2.90 (2.9%)
            $table->decimal('transaction_fee_fixed', 10, 2)->default(0);//កម្រៃសេវាថេរ ឧទាហរណ៍: 0.30 ($0.30 per transaction)
            
            $table->enum('status', ['active', 'inactive', 'maintenance'])
                  ->default('active');
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('gateway_code');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_getways');
    }
};
