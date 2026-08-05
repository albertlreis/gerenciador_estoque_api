<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('comunicacao_jornadas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 100)->unique();
            $table->string('nome', 150);
            $table->enum('tipo', ['pedido', 'cobranca']);
            $table->boolean('ativo')->default(false)->index();
            $table->string('timezone', 60)->default('America/Belem');
            $table->json('agenda')->nullable();
            $table->unsignedInteger('versao')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('comunicacao_jornada_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('comunicacao_jornadas')->cascadeOnDelete();
            $table->string('evento_codigo', 100);
            $table->timestamps();
            $table->unique(['jornada_id', 'evento_codigo']);
        });

        Schema::create('comunicacao_jornada_canais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('comunicacao_jornadas')->cascadeOnDelete();
            $table->enum('canal', ['email', 'sms', 'whatsapp']);
            $table->string('template_codigo', 120);
            $table->boolean('ativo')->default(false);
            $table->timestamps();
            $table->unique(['jornada_id', 'canal']);
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('bloqueia_email')->default(false)->after('email');
        });

        Schema::create('cliente_comunicacao_consentimentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cliente_id');
            $table->enum('canal', ['sms', 'whatsapp']);
            $table->enum('situacao', ['concedido', 'revogado']);
            $table->string('origem', 80);
            $table->timestamp('decidido_em');
            $table->unsignedBigInteger('responsavel_id')->nullable();
            $table->string('referencia_evidencia', 190)->nullable();
            $table->timestamps();
            $table->unique(['cliente_id', 'canal']);
            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
        });

        Schema::create('comunicacao_eventos_saida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->nullable()->constrained('comunicacao_jornadas')->nullOnDelete();
            $table->unsignedInteger('cliente_id')->nullable();
            $table->string('origem_tipo', 40);
            $table->unsignedBigInteger('origem_id');
            $table->string('evento_codigo', 100);
            $table->enum('canal', ['email', 'sms', 'whatsapp']);
            $table->string('template_codigo', 120);
            $table->string('destinatario', 255)->nullable();
            $table->json('variaveis')->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->uuid('correlation_id')->index();
            $table->enum('status', ['pendente', 'processando', 'enviado', 'ignorado', 'falho'])
                ->default('pendente')->index();
            $table->unsignedInteger('tentativas')->default(0);
            $table->timestamp('disponivel_em')->nullable()->index();
            $table->timestamp('processado_em')->nullable();
            $table->string('erro_codigo', 80)->nullable();
            $table->string('erro_mensagem', 255)->nullable();
            $table->timestamps();
            $table->index(['origem_tipo', 'origem_id']);
            $table->index(['cliente_id', 'canal', 'status']);
            $table->foreign('cliente_id')->references('id')->on('clientes')->nullOnDelete();
            $table->index(['status', 'disponivel_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comunicacao_eventos_saida');
        Schema::dropIfExists('cliente_comunicacao_consentimentos');
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('bloqueia_email');
        });
        Schema::dropIfExists('comunicacao_jornada_canais');
        Schema::dropIfExists('comunicacao_jornada_eventos');
        Schema::dropIfExists('comunicacao_jornadas');
    }
};
