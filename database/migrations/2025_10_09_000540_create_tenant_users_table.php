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
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_id')->index('idx_tenant_users_user_id');
            $table->foreign('user_id', 'fk_tenant_users_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('tenant_id')->index('idx_tenant_users_tenant_id');
            $table->foreign('tenant_id', 'fk_tenant_users_tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
