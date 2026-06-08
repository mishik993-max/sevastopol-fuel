<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('field', 20);
            $table->string('proposed_name', 120)->nullable();
            $table->string('proposed_address', 255)->nullable();
            $table->decimal('proposed_latitude', 10, 7)->nullable();
            $table->decimal('proposed_longitude', 10, 7)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('proposer_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('applied_at')->nullable();

            $table->index(['station_id', 'field', 'status']);
        });

        Schema::create('station_correction_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correction_id')->constrained('station_corrections')->cascadeOnDelete();
            $table->string('reporter_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['correction_id', 'reporter_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_correction_reports');
        Schema::dropIfExists('station_corrections');
    }
};
