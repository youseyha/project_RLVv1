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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('item_id')->primary();

            $table->foreignUuid('invoice_id')
                  ->constrained('invoices', 'invoice_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // Indexes
            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
