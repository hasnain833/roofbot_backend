<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable(); 
            $table->text('message');                        
            $table->enum('sender_type', ['user', 'bot']);       
            $table->timestamps();                            
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
