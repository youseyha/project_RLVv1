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
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid("branch_id")->primary();
            $table->uuid('tenant_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->string('address')->nullable();
            $table->string('phone',15)->unique()->nullable();
            $table->string('manager_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            //unique constraint to ensure branch_code is unique within the same tenant
            $table->unique(['tenant_id', 'branch_code']);
            // Indexes for performance
            $table->index('tenant_id');
            $table->index('branch_code');
            $table->index('is_active');

            $table->foreign('tenant_id')
            ->references('tenant_id')
            ->on('tenants')
            ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
