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
        //ជំហានទី 1: បង្កើតតារាងដោយគ្មាន self-referencing FK
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid("category_id")->primary();
            $table->foreignUuid("tenant_id")
                  ->constrained("tenants","tenant_id")
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->string("category_name");
            $table->text("description")->nullable();
            $table->uuid("parent_category_id")->nullable();  
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true); 
            $table->timestamps();

            $table->index('parent_category_id');
            $table->index(['tenant_id', 'parent_category_id']);

            $table->unique(['tenant_id', 'category_name']);
        });
        // ជំហានទី 2: បន្ថែម self-referencing FK នៅពេលក្រោយ
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreign('parent_category_id')
                  ->references('category_id')
                  ->on('product_categories')
                  ->nullOnDelete()
                  ->cascadeOnUpdate();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
