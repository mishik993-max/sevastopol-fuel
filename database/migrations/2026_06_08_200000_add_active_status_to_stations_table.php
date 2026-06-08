<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('longitude');
            $table->timestamp('closed_at')->nullable()->after('is_active');
            $table->string('closed_reason')->nullable()->after('closed_at');

            $table->index('is_active');
        });

        Schema::create('station_closure_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('reporter_hash', 64);
            $table->string('comment', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['station_id', 'created_at']);
            $table->unique(['station_id', 'reporter_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_closure_reports');

        Schema::table('stations', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_active', 'closed_at', 'closed_reason']);
        });
    }
};
