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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('subscription_id')->primary();
            $table->foreignUuid('tenant_id')
                  ->constrained('tenants', 'tenant_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid('plan_id')
                  ->constrained('subscription_plans', 'plan_id')
                  ->restrictOnDelete()  //ការពារមិនឱ្យលុប plan ដែលកំពុងប្រើ
                  ->cascadeOnUpdate();
            $table->uuid('current_plan_id')
                  ->nullable()
                  ->constrained('subscription_plans', 'plan_id')
                  ->nullOnDelete()
                  ->cascadeOnUpdate();
            $table->uuid('pending_plan_id')
                  ->nullable()
                  ->constrained('subscription_plans', 'plan_id')
                  ->nullOnDelete()
                  ->cascadeOnUpdate();
            $table->timestamp('change_plan_at')->nullable();
            $table->datetime('start_date');
            $table->datetime('end_date');
            $table->datetime('next_billing_date');
            $table->enum('status', ['active', 'suspended', 'cancelled', 'expired','pending'])
                  ->default('pending');
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();

            // Indexes សម្រាប់ performance
            $table->index('tenant_id');
            $table->index('plan_id');
            $table->index('status');
            $table->index('next_billing_date');
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
