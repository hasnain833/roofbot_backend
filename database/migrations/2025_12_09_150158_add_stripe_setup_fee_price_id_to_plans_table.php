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
    Schema::table('plans', function (Blueprint $table) {
        $table->string('stripe_setup_fee_price_id')->nullable()->after('setup_fee');
    });
}

public function down()
{
    Schema::table('plans', function (Blueprint $table) {
        $table->dropColumn('stripe_setup_fee_price_id');
    });
}

};
