<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('confirms_report_id')->nullable()->after('is_confirmation')->constrained('reports')->nullOnDelete();
            $table->string('reporter_hash', 64)->nullable()->after('confirms_report_id');
            $table->unique(['confirms_report_id', 'reporter_hash'], 'reports_confirm_once');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique('reports_confirm_once');
            $table->dropConstrainedForeignId('confirms_report_id');
            $table->dropColumn('reporter_hash');
        });
    }
};
