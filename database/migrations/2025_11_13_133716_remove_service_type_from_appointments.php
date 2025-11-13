<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   // database/migrations/xxxx_remove_service_type_from_appointments.php
public function up()
{
    Schema::table('appointments', function (Blueprint $table) {
        $table->dropColumn('service_type');
    });
}

public function down()
{
    Schema::table('appointments', function (Blueprint $table) {
        $table->string('service_type')->nullable();
    });
}
};
