<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_assistant_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_id')->index();
            $table->string('status', 20)->default('active');
            $table->json('messages');
            $table->json('draft')->nullable();
            $table->json('preview')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_assistant_sessions');
    }
};
