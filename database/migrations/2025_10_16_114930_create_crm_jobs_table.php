<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
           $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
             $table->string('service_type'); // Roofing, Gutters, Siding, Windows, Other
            $table->string('status')->default('Active'); // Active, Completed, Cancelled
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_jobs');
    }
};
