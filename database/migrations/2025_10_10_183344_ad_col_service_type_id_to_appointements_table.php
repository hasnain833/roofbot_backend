<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
        $table->unsignedBigInteger('service_type_id')->nullable()->index('idx_appointments_service_type_id');
$table->foreign('service_type_id', 'fk_appointments_service_type_id')
      ->references('id')->on('service_types')->onDelete('set null');
  });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['service_type_id']);
        });
    }
};
