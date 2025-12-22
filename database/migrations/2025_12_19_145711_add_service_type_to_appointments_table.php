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
    Schema::table('appointments', function (Blueprint $table) {
        $table->string('service_type')->nullable()->after('description');
    });
}

public function down()
{
    Schema::table('appointments', function (Blueprint $table) {
        $table->dropColumn('service_type');
    });
}

};
