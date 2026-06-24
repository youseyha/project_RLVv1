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
        Schema::create('inventories', function (Blueprint $table) {
            $table->uuid("inventory_id")->primary();
            $table->foreignUuid("branch_id")
                  ->constrained("branches","branch_id")
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid("product_id")
                  ->constrained("products","product_id")
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('quantity_reserved', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->decimal('reorder_quantity', 12, 2)->default(0);
            $table->timestamp('last_updated')->useCurrent()->useCurrentOnUpdate();
            $table->timestamps();

            // Unique constraint: មួយផលិតផលក្នុងមួយសាខា
            $table->unique(['branch_id', 'product_id']);
            // Indexes សម្រាប់ performance
            $table->index('product_id');
            $table->index('branch_id');
            $table->index(['product_id', 'quantity_on_hand']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
