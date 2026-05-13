<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('google_calendar_conexoes')) {
            Schema::create('google_calendar_conexoes', function (Blueprint $table) {
                $table->id();
                $table->string('status', 32)->default('inativa')->index();
                $table->string('email_externo', 190)->nullable();
                $table->string('nome_externo', 190)->nullable();
                $table->dateTime('ultimo_healthcheck_em')->nullable()->index();
                $table->text('ultimo_erro')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('google_calendar_tokens')) {
            Schema::create('google_calendar_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conexao_id')->unique();
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->string('scope', 512)->nullable();
                $table->dateTime('ultimo_refresh_em')->nullable();
                $table->timestamps();

                $table->foreign('conexao_id')->references('id')->on('google_calendar_conexoes')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('google_calendar_calendars')) {
            Schema::create('google_calendar_calendars', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conexao_id')->index();
                $table->string('calendar_id', 255);
                $table->string('summary', 255);
                $table->text('description')->nullable();
                $table->string('timezone', 80)->nullable();
                $table->string('access_role', 40)->nullable()->index();
                $table->boolean('primary')->default(false)->index();
                $table->boolean('enabled')->default(false)->index();
                $table->string('background_color', 32)->nullable();
                $table->string('foreground_color', 32)->nullable();
                $table->dateTime('synced_at')->nullable()->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->foreign('conexao_id')->references('id')->on('google_calendar_conexoes')->cascadeOnDelete();
                $table->unique(['conexao_id', 'calendar_id'], 'gc_cal_conexao_calendar_unq');
            });
        }

        if (!Schema::hasTable('google_calendar_logs')) {
            Schema::create('google_calendar_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conexao_id')->nullable()->index();
                $table->unsignedBigInteger('usuario_id')->nullable()->index();
                $table->string('calendar_id', 255)->nullable()->index();
                $table->string('event_id', 255)->nullable()->index();
                $table->string('acao', 32)->index();
                $table->string('status', 32)->index();
                $table->text('request_resumo')->nullable();
                $table->text('response_resumo')->nullable();
                $table->string('erro_codigo', 64)->nullable();
                $table->text('erro_mensagem')->nullable();
                $table->dateTime('executado_em')->nullable()->index();
                $table->timestamps();

                $table->foreign('conexao_id')->references('id')->on('google_calendar_conexoes')->nullOnDelete();
                $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_logs');
        Schema::dropIfExists('google_calendar_calendars');
        Schema::dropIfExists('google_calendar_tokens');
        Schema::dropIfExists('google_calendar_conexoes');
    }
};
