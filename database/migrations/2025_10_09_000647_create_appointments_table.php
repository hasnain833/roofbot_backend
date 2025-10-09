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
            $table->unsignedBigInteger('user_id')->index('idx_appointments_user_id')->nullable();
            $table->foreign('user_id', 'fk_appointments_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('tenant_id')->index('idx_appointments_tenant_id')->nullable();
            $table->foreign('tenant_id', 'fk_appointments_tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('lead_id')->index('idx_appointments_lead_id')->nullable();
            $table->foreign('lead_id', 'fk_appointments_lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('notes')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->string('priority')->nullable();
            $table->string('reminder')->nullable();
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
