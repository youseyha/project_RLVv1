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
        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('feature_id')->primary();
            $table->foreignUuid('plan_id')
                  ->constrained('subscription_plans', 'plan_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();    
            $table->string('feature_name');
            $table->string('feature_code')->unique();
            $table->boolean('is_enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            // Indexes
            $table->index('plan_id');
            $table->index('feature_code');
            $table->index(['plan_id', 'is_enabled']);
            
            // Unique constraint: មួយ feature_code ក្នុងមួយ plan
            $table->unique(['plan_id', 'feature_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
