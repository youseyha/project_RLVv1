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
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->uuid('report_id')->primary();
            
            $table->foreignUuid('tenant_id')
                  ->constrained('tenants', 'tenant_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            
            $table->foreignUuid('branch_id')
                  ->nullable()
                  ->constrained('branches', 'branch_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->date('report_date');
            
            // Sales metrics
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->integer('transaction_count')->default(0);
            $table->decimal('average_transaction', 12, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_discount', 15, 2)->default(0);
            $table->integer('customer_count')->default(0);
            
            $table->timestamp('generated_at')->useCurrent();
            
            // Unique constraint: មួយរបាយការណ៍ក្នុងមួយថ្ងៃក្នុងមួយសាខា
            $table->unique(['branch_id', 'report_date']);
            
            // Indexes សម្រាប់ performance
            $table->index('tenant_id');
            $table->index('branch_id');
            $table->index('report_date');
            $table->index(['tenant_id', 'report_date']);
            $table->index(['branch_id', 'report_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
