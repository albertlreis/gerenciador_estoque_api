<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_eventos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('created_at')->useCurrent();

            $table->string('actor_type', 20)->default('SYSTEM');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id');

            $table->string('module', 40);
            $table->string('action', 40);
            $table->string('label', 255);

            $table->string('request_id', 36)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('origin', 20)->default('API');
            $table->json('metadata_json')->nullable();

            $table->string('prev_hash', 128)->nullable();
            $table->string('event_hash', 128)->nullable();

            $table->index('created_at', 'idx_auditoria_eventos_created_at');
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'idx_auditoria_eventos_auditable_created');
            $table->index(['actor_id', 'created_at'], 'idx_auditoria_eventos_actor_created');
            $table->index(['module', 'action', 'created_at'], 'idx_auditoria_eventos_module_action_created');
            $table->index('request_id', 'idx_auditoria_eventos_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_eventos');
    }
};
