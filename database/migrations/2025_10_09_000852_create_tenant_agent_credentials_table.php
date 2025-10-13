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
        Schema::create('tenant_agent_integrations', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('tenant_agent_id')->index('idx_tenant_agent_integrations_tenant_agent_id');
            $table->foreign('tenant_agent_id', 'fk_tenant_agent_integrations_tenant_agent_id')->references('id')->on('tenant_agents')->onDelete('cascade');
            $table->string('provider');
            $table->text('key');
            $table->text('secret');
            $table->text('meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_agent_integrations');
    }
};
