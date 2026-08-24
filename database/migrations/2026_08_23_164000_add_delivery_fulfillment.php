<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('city', 80)->nullable()->after('address');
            $table->string('province', 80)->nullable()->after('city');
            $table->string('island_group', 16)->nullable()->after('province');
            $table->string('local_area', 80)->nullable()->after('island_group');
        });

        Schema::create('delivery_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('same_city_fee', 10, 2)->default(80);
            $table->decimal('same_province_fee', 10, 2)->default(150);
            $table->decimal('luzon_fee', 10, 2)->default(450);
            $table->decimal('visayas_fee', 10, 2)->default(350);
            $table->decimal('mindanao_fee', 10, 2)->default(250);
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('delivery_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('vendor_delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('same_city_fee', 10, 2)->nullable();
            $table->decimal('same_province_fee', 10, 2)->nullable();
            $table->decimal('luzon_fee', 10, 2)->nullable();
            $table->decimal('visayas_fee', 10, 2)->nullable();
            $table->decimal('mindanao_fee', 10, 2)->nullable();
            $table->boolean('pickup_enabled')->nullable();
            $table->boolean('delivery_enabled')->nullable();
            $table->timestamps();
        });

        Schema::table('product_orders', function (Blueprint $table) {
            $table->decimal('product_amount', 10, 2)->nullable()->after('unit_price');
            $table->decimal('delivery_fee', 10, 2)->default(0)->after('product_amount');
            $table->string('fulfillment', 16)->default('pickup')->after('delivery_fee');
            $table->string('delivery_zone', 24)->nullable()->after('fulfillment');
            $table->string('ship_name', 120)->nullable()->after('delivery_zone');
            $table->string('ship_phone', 40)->nullable()->after('ship_name');
            $table->string('ship_address', 255)->nullable()->after('ship_phone');
            $table->string('ship_city', 80)->nullable()->after('ship_address');
            $table->string('ship_province', 80)->nullable()->after('ship_city');
            $table->string('ship_island_group', 16)->nullable()->after('ship_province');
        });

        DB::table('delivery_settings')->insert([
            'same_city_fee' => 80,
            'same_province_fee' => 150,
            'luzon_fee' => 450,
            'visayas_fee' => 350,
            'mindanao_fee' => 250,
            'pickup_enabled' => true,
            'delivery_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_orders', function (Blueprint $table) {
            $table->dropColumn([
                'product_amount',
                'delivery_fee',
                'fulfillment',
                'delivery_zone',
                'ship_name',
                'ship_phone',
                'ship_address',
                'ship_city',
                'ship_province',
                'ship_island_group',
            ]);
        });
        Schema::dropIfExists('vendor_delivery_rates');
        Schema::dropIfExists('delivery_settings');
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['city', 'province', 'island_group', 'local_area']);
        });
    }
};
