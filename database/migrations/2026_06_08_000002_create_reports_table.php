<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('fuel_type', 20);
            $table->string('status', 20);
            $table->string('queue_size', 20);
            $table->text('comment')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_confirmation')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['station_id', 'fuel_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
