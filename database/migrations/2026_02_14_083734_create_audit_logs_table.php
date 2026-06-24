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
        Schema::create('audit_logs', function (Blueprint $table) {
            // Primary Key
            $table->uuid('log_id')->primary();
            // Foreign Keys
            $table->foreignUuid('user_id')
                  ->nullable()
                  ->constrained('users', 'user_id')
                  ->onDelete('set null');  // រក្សា log ទោះបី user លុបក៏ដោយ
            
            $table->foreignUuid('tenant_id')
                  ->constrained('tenants', 'tenant_id')
                  ->onDelete('cascade');
            
            // Action Info
            $table->enum('action_type', ['create', 'update', 'delete', 'view', 'login', 'logout'])
                  ->index();
            $table->string('table_name', 100)->nullable()->index();
            $table->uuid('record_id')->nullable()->index();
            
            // Data Changes
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            
            // Request Info
            $table->string('ip_address', 50)->nullable();
            $table->text('user_agent')->nullable();
            
            // Timestamp
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for Performance
            $table->index('created_at');
            $table->index('tenant_id');
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'table_name']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
