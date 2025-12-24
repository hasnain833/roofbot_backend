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
       Schema::create('lead_custom_answers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    $table->string('question');
    $table->text('answer');
    $table->timestamps();

    $table->unique(['lead_id', 'question']); // prevent duplicates
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_custom_answers');
    }
};
