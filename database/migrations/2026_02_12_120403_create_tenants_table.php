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
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid("tenant_id")->primary();
            $table->string("company_name");
            $table->string("busines_type");
            $table->string("email")->unique();
            $table->string("phone",15)->unique();
            $table->string("address")->nullable();
            $table->string("url_logo")->nullable();
            $table->enum("status",["active","suspended","terminated"]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
