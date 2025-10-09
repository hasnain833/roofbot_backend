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
        Schema::create('tenant_jobs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_id')->index('idx_tenant_jobs_user_id')->nullable();
            $table->foreign('user_id', 'fk_tenant_jobs_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('tenant_id')->index('idx_tenant_jobs_tenant_id')->nullable();
            $table->foreign('tenant_id', 'fk_tenant_jobs_tenant_id')->references('id')->on('tenants')->onDelete('cascade');
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
        Schema::dropIfExists('tenant_jobs');
    }
};
