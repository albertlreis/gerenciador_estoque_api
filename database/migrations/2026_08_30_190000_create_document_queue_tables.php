<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        Schema::create('document_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 20)->default('pending')->index();
            $table->json('request_payload');
            $table->string('request_method', 10)->default('GET');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_exports');
        // jobs/failed_jobs may predate this migration and are shared queue infrastructure.
        // Never remove them during an application rollback.
    }
};
