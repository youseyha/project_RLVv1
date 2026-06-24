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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('plan_id')->primary();
            $table->string('plan_name')->unique();
            $table->text('description')->nullable();
            // Pricing
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('yearly_price', 10, 2);
            // Limits
            $table->integer('max_branches')->default(1);
            $table->integer('max_users')->default(5);
            $table->integer('max_pos_terminals')->default(2);
            $table->integer('transaction_limit_monthly')->nullable();  // null = unlimited
            // Features
            $table->boolean('has_analytics')->default(false);
            $table->boolean('has_api_access')->default(false);
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('plan_name');
            $table->index('is_active');
            $table->index(['is_active', 'monthly_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
