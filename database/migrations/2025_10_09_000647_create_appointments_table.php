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
    Schema::create('appointments', function (Blueprint $table) {
        $table->id();
        $table->timestamps();

        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

        $table->unsignedBigInteger('lead_id')->nullable()->index();
        $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');

        $table->string('title');
        $table->text('description')->nullable();
        $table->text('notes')->nullable();

        $table->string('status')->default('scheduled');
        $table->string('service_type')->nullable();
        $table->timestamp('start_time')->nullable();
        $table->timestamp('end_time')->nullable();
        $table->string('google_event_id')->nullable();
        $table->boolean('reminder_sent')->default(false);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
