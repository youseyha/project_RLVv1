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
        Schema::create('products', function (Blueprint $table) {
            $table->uuid("product_id")->primary();
            $table->foreignUuid("tenant_id")
                  ->constrained("tenants","tenant_id")
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->foreignUuid("category_id")
                  ->nullable()
                  ->constrained("product_categories","category_id")
                  ->cascadeOnUpdate()
                  ->nullOnDelete();
            $table->string("product_code");
            $table->string("product_name");
            $table->text("description")->nullable();
            $table->decimal("base_price",12,2);
            $table->decimal("cost_price",12,2);
            $table->string("image_url")->nullable();
            $table->boolean("is_active")->default(true);
            $table->integer("stock_quantity")->default(0);
            $table->timestamps();
            
            $table->unique(["product_code","tenant_id"]);
           // Indexes សម្រាប់ performance
            $table->index('category_id');
            $table->index('is_active');
            $table->index(['tenant_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
