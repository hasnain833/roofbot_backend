<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('users', function (Blueprint $table) {
        $table->integer('plan_id')->default(1)->after('role'); // 1 = starter
        $table->string('subscription_status')->default('pending')->after('plan_id'); // pending, active, cancelled
        $table->string('stripe_customer_id')->nullable()->after('subscription_status');
    });
}

public function down()
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['plan_id', 'subscription_status', 'stripe_customer_id']);
    });
}

};
