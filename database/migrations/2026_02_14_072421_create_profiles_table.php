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
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid("profile_id")->primary();
            $table->foreignUuid("user_id")
                  ->constrained("users","user_id")
                  ->cascadeOnDelete()
                  ->cascadeOnUpdate();
            $table->string("phone_number",15)->unique();
            $table->string("avater_url")->nullable();
            $table->date("date_of_birth")->nullable();
            $table->enum("gender",["female","male"]);
            $table->string("address")->nullable();
            $table->string("city")->nullable();
            $table->string("state")->nullable();
            $table->string("postal_code");
            $table->string("country");
            $table->string("emergency_contact_name",100)->nullable();
            $table->string("emergency_contact_phone",15)->nullable();
            $table->text("bio")->nullable();
            $table->timestamps();

            // Indexes for Performance
            $table->index('user_id');
            $table->index('postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
