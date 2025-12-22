<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->unsignedBigInteger('appointment_id')->nullable()->after('lead_id');
            $table->foreign('appointment_id')->references('id')->on('appointments')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');
        });
    }
};