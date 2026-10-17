<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_results', function (Blueprint $table) {
            $table->string('ai_severity')->nullable()->after('scan_results');
            $table->string('ai_urgency')->nullable()->after('ai_severity');
            $table->json('ai_analysis')->nullable()->after('ai_urgency');
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_analysis');

            $table->index('ai_severity');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_results', function (Blueprint $table) {
            $table->dropIndex(['ai_severity']);
            $table->dropColumn(['ai_severity', 'ai_urgency', 'ai_analysis', 'ai_analyzed_at']);
        });
    }
};
