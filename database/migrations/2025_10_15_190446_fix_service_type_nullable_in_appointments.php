<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // ✅ Make column nullable if it already exists
            $table->unsignedBigInteger('service_type_id')->nullable()->change();

            // // ✅ Add foreign key if not exists (PostgreSQL may fail if already exists)
            // // Give explicit name to avoid auto-name conflicts
            // $table->foreign('service_type_id', 'fk_appointments_service_type_id')
            //       ->references('id')
            //       ->on('service_types')
            //       ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // ✅ Drop the foreign key by explicit name
            $table->dropForeign('fk_appointments_service_type_id');

            // ✅ Make column not nullable
            $table->unsignedBigInteger('service_type_id')->nullable(false)->change();
        });
    }
};
