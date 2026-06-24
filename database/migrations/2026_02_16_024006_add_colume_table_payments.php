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
        Schema::table('payments', function (Blueprint $table) {
            // បន្ថែម FK columns
            $table->foreignUuid('method_id')
                  ->nullable()
                  ->after('transaction_id')
                  ->constrained('payment_methods', 'method_id')
                  ->nullOnDelete();
            
            $table->foreignUuid('gateway_id')
                  ->nullable()
                  ->after('method_id')
                  ->constrained('payment_getways', 'gateway_id')
                  ->nullOnDelete();
            // បន្ថែម snapshot columns សម្រាប់ history
            $table->json('method_snapshot')->nullable();
            $table->json('gateway_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['method_id']);
            $table->dropForeign(['gateway_id']);
            $table->dropColumn(['method_id', 'gateway_id', 'method_snapshot', 'gateway_snapshot']);
        });
    }
};
