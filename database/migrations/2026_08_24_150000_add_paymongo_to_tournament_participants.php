<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->decimal('entry_fee_amount', 10, 2)->default(0)->after('status');
            $table->string('payment_status', 24)->default('free')->after('entry_fee_amount');
            $table->string('payment_method', 24)->nullable()->after('payment_status');
            $table->string('reference_number', 40)->nullable()->unique()->after('payment_method');
            $table->string('paymongo_checkout_id', 64)->nullable()->index()->after('reference_number');
            $table->string('paymongo_payment_id', 64)->nullable()->after('paymongo_checkout_id');
            $table->timestamp('paid_at')->nullable()->after('paymongo_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropColumn([
                'entry_fee_amount',
                'payment_status',
                'payment_method',
                'reference_number',
                'paymongo_checkout_id',
                'paymongo_payment_id',
                'paid_at',
            ]);
        });
    }
};
