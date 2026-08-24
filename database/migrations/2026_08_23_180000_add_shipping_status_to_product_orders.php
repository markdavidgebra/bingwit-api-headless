<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_orders', function (Blueprint $table) {
            $table->string('shipping_status', 32)->nullable()->after('fulfillment');
            $table->timestamp('shipping_updated_at')->nullable()->after('shipping_status');
            $table->json('shipping_events')->nullable()->after('shipping_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_status', 'shipping_updated_at', 'shipping_events']);
        });
    }
};
