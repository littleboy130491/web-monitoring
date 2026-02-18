<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_results', function (Blueprint $table) {
            $table->date('domain_expires_at')->nullable()->after('ssl_info');
            $table->integer('domain_days_until_expiry')->nullable()->after('domain_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_results', function (Blueprint $table) {
            $table->dropColumn(['domain_expires_at', 'domain_days_until_expiry']);
        });
    }
};
