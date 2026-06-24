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
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->uuid("terminal_id")->primary();
            $table->foreignUuid('branch_id')
                  ->constrained('branches', 'branch_id')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
            $table->string('terminal_code', 50);
            $table->string('device_id', 100)->nullable()->unique();
            $table->string('ip_address', 50)->nullable();
            $table->enum("status",["online","offline","maintenance"])->default('offline');
            // Sync Tracking
            $table->timestamp('last_sync')->nullable();
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            // Indexes for performance
            $table->index('branch_id');
            $table->index('terminal_code');
            $table->index('device_id');
            $table->index('status');
            $table->index('last_sync');
            // Unique Constraints
            $table->unique(['branch_id', 'terminal_code'], 'unique_branch_terminal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
    }
};
