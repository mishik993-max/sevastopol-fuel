<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscription_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('push_subscription_id')->constrained('push_subscriptions')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->string('fuel_type', 20);
            $table->boolean('notify_available')->default(true);
            $table->string('last_marker_color', 10)->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['push_subscription_id', 'station_id']);
            $table->index(['station_id', 'fuel_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscription_watches');
    }
};
