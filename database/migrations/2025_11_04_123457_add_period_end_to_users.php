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
        $table->timestamp('current_period_end')->nullable()->after('stripe_customer_id');
    });
}
public function down()
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('current_period_end');
    });
}
};
