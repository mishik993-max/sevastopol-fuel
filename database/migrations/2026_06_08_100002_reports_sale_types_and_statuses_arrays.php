<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('sale_types')->nullable()->after('queue_size');
            $table->json('statuses')->nullable()->after('status');
        });

        DB::table('reports')->orderBy('id')->chunkById(100, function ($reports) {
            foreach ($reports as $report) {
                $saleTypes = match ($report->sale_type ?? 'regular') {
                    'voucher_qr' => ['voucher', 'qr'],
                    'voucher' => ['voucher'],
                    'qr' => ['qr'],
                    default => ['regular'],
                };

                DB::table('reports')->where('id', $report->id)->update([
                    'sale_types' => json_encode($saleTypes, JSON_THROW_ON_ERROR),
                    'statuses' => json_encode([$report->status], JSON_THROW_ON_ERROR),
                ]);
            }
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('sale_type');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('sale_type', 20)->default('regular')->after('queue_size');
        });

        DB::table('reports')->orderBy('id')->chunkById(100, function ($reports) {
            foreach ($reports as $report) {
                $saleTypes = json_decode($report->sale_types ?? '[]', true) ?: ['regular'];
                $saleType = match (true) {
                    in_array('voucher', $saleTypes, true) && in_array('qr', $saleTypes, true) => 'voucher_qr',
                    in_array('voucher', $saleTypes, true) => 'voucher',
                    in_array('qr', $saleTypes, true) => 'qr',
                    default => 'regular',
                };

                DB::table('reports')->where('id', $report->id)->update([
                    'sale_type' => $saleType,
                ]);
            }
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['sale_types', 'statuses']);
        });
    }
};
