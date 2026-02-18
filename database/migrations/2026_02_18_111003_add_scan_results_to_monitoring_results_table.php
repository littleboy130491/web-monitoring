<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_results', function (Blueprint $table) {
            $table->json('scan_results')->nullable()->after('domain_days_until_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_results', function (Blueprint $table) {
            $table->dropColumn('scan_results');
        });
    }
};
