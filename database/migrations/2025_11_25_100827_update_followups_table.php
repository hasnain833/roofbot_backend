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
    Schema::table('followups', function (Blueprint $table) {
        if (!Schema::hasColumn('followups', 'sent')) {
            $table->boolean('sent')->default(false);
        }
        if (!Schema::hasColumn('followups', 'attempt_number')) {
            $table->integer('attempt_number')->default(1);
        }
        if (!Schema::hasColumn('followups', 'type')) {
            $table->string('type')->nullable(); 
        }
    });
}

public function down()
{
    Schema::table('followups', function (Blueprint $table) {
        $table->dropColumn(['sent', 'attempt_number', 'type']);
    });
}

};
