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
       Schema::create('plans', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->decimal('monthly_price', 10, 2)->nullable();
    $table->decimal('yearly_price', 10, 2)->nullable();
    $table->decimal('setup_fee', 10, 2)->default(0);
    $table->string('stripe_monthly_price_id')->nullable();
    $table->string('stripe_yearly_price_id')->nullable();
    $table->text('description')->nullable();
    $table->boolean('is_popular')->default(false);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
