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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid("transaction_id")->primary();
            $table->foreignUuid("branch_id")
                  ->constrained("branches","branch_id")
                  ->restrictOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid("terminal_id")
                  ->nullable()
                  ->constrained("pos_terminals","terminal_id")
                  ->restrictOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid("user_id")
                  ->constrained("users","user_id")
                  ->restrictOnDelete()
                  ->cascadeOnUpdate();
            $table->string("transaction_number");
            $table->dateTime("transaction_date");
            $table->decimal("subtotal",12,2);
            $table->decimal("tax_amount",12,2);
            $table->decimal("discount_amount",12,2);
            $table->decimal("total_amount",12,2);
            $table->enum("status",["pending","completed","cancelled","refunded"])->default("pending");
            $table->text("notes")->nullable();
            $table->timestamps();

            // Indexes for Performance
            $table->index('status');
            $table->index('branch_id');
            $table->index("user_id");
            $table->index("terminal_id");
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'user_id']);
            $table->index(['branch_id', 'terminal_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
