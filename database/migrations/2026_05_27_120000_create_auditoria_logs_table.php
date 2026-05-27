<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('auditoria_logs')) {
            Schema::create('auditoria_logs', function (Blueprint $table) {
                $table->id();
                $table->dateTime('occurred_at')->index();

                $table->string('tipo', 40)->default('log')->index();
                $table->string('categoria', 40)->default('tecnico')->index();
                $table->string('nivel', 20)->nullable()->index();
                $table->string('modulo', 80)->nullable()->index();
                $table->string('acao', 80)->nullable()->index();
                $table->string('status', 60)->nullable()->index();
                $table->string('label', 255)->nullable();
                $table->text('message')->nullable();

                $table->string('actor_type', 120)->nullable();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('actor_name', 255)->nullable();

                $table->string('entity_type', 190)->nullable();
                $table->string('entity_id', 120)->nullable();

                $table->string('source_system', 40)->default('estoque')->index();
                $table->string('source_kind', 60)->nullable()->index();
                $table->string('source_table', 120)->nullable()->index();
                $table->string('source_id', 120)->nullable()->index();
                $table->char('source_uid', 64)->nullable()->unique();

                $table->string('origem', 60)->nullable()->index();
                $table->string('route', 255)->nullable();
                $table->string('method', 10)->nullable();
                $table->string('ip', 45)->nullable();
                $table->text('user_agent')->nullable();

                $table->json('metadata_json')->nullable();
                $table->json('context_json')->nullable();
                $table->longText('raw_excerpt')->nullable();
                $table->unsignedSmallInteger('retention_days')->default(90)->index();

                $table->timestamps();

                $table->index(['entity_type', 'entity_id'], 'idx_audit_logs_entity');
                $table->index(['source_system', 'source_kind'], 'idx_audit_logs_source');
                $table->index(['categoria', 'occurred_at'], 'idx_audit_logs_category_time');
                $table->index(['modulo', 'acao', 'occurred_at'], 'idx_audit_logs_module_action_time');
            });
        }

        if (!Schema::hasTable('auditoria_log_mudancas')) {
            Schema::create('auditoria_log_mudancas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('auditoria_log_id')->constrained('auditoria_logs')->cascadeOnDelete();
                $table->string('campo', 120);
                $table->longText('old_value')->nullable();
                $table->longText('new_value')->nullable();
                $table->string('value_type', 40)->default('string');
                $table->timestamps();

                $table->index(['auditoria_log_id', 'campo'], 'idx_audit_log_changes_log_field');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_log_mudancas');
        Schema::dropIfExists('auditoria_logs');
    }
};
