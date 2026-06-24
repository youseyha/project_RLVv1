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
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid("item_id")->primary();
            $table->foreignUuid("transaction_id")
                  ->constrained("transactions","transaction_id")
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid("product_id")
                  ->constrained("products","product_id")
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();
            $table->string('product_name');  // រក្សាទុកឈ្មោះពេលលក់ (ករណីផលិតផលប្តូរឈ្មោះ)
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);  // (quantity * unit_price) - discount
            $table->timestamp('created_at')->useCurrent();

            // Indexes សម្រាប់ performance
            $table->index('transaction_id');
            $table->index('product_id');
            $table->index(['transaction_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
