<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_daily_stats', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('total_visits')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('visitor_daily_uniques', function (Blueprint $table) {
            $table->date('date');
            $table->string('visitor_id', 36);
            $table->timestamp('first_seen_at');

            $table->primary(['date', 'visitor_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_daily_uniques');
        Schema::dropIfExists('visitor_daily_stats');
    }
};
