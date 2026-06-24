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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('movement_id')->primary();
            $table->foreignUuid('inventory_id')
                  ->constrained('inventories', 'inventory_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid('product_id')
                  ->constrained('products', 'product_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid('branch_id')
                  ->constrained('branches', 'branch_id')
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid('user_id')
                  ->nullable()
                  ->constrained('users', 'user_id')
                  ->nullOnDelete();
            
            $table->enum('movement_type', [
                'purchase',      // ទិញចូល
                'sale',          // លក់ចេញ
                'adjustment_in', // កែប្រែបង្កើន
                'adjustment_out',// កែប្រែបន្ថយ
                'transfer_in',   // ទទួលពីសាខាផ្សេង
                'transfer_out',  // ផ្ញើទៅសាខាផ្សេង
                'damage',        // ខូច
                'return_to_supplier',        // អ្នកផ្គត់ផ្គង់ត្រឡប់
                'return_from_customer' // អតិថិជនត្រឡប់
            ]);
            
            $table->decimal('quantity', 12, 2);
            $table->decimal('quantity_before', 12, 2);
            $table->decimal('quantity_after', 12, 2);
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['product_id', 'branch_id']);
            $table->index('movement_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
