<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monitoring_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->integer('status_code')->nullable();
            $table->integer('response_time')->nullable(); // milliseconds
            $table->string('status')->default('unknown'); // up, down, error, timeout
            $table->text('error_message')->nullable();
            $table->text('content_hash')->nullable();
            $table->boolean('content_changed')->default(false);
            $table->string('screenshot_path')->nullable();
            $table->json('headers')->nullable();
            $table->json('ssl_info')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            
            $table->index(['website_id', 'checked_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_results');
    }
};
