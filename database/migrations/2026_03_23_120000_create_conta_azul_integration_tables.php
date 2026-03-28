<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('conta_azul_conexoes')) {
            Schema::create('conta_azul_conexoes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loja_id')->nullable()->index();
                $table->string('status', 32)->default('inativa')->index();
                $table->string('ambiente', 32)->default('producao')->index();
                $table->string('nome_externo', 190)->nullable();
                $table->text('observacoes')->nullable();
                $table->dateTime('ultimo_healthcheck_em')->nullable()->index();
                $table->text('ultimo_erro')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('conta_azul_tokens')) {
            Schema::create('conta_azul_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conexao_id')->unique();
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->string('scope', 512)->nullable();
                $table->dateTime('ultimo_refresh_em')->nullable();
                $table->timestamps();

                $table->foreign('conexao_id')->references('id')->on('conta_azul_conexoes')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('conta_azul_mapeamentos')) {
            Schema::create('conta_azul_mapeamentos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loja_id')->nullable()->index();
                $table->string('tipo_entidade', 32)->index();
                $table->unsignedBigInteger('id_local')->nullable()->index();
                $table->string('id_externo', 64)->nullable()->index();
                $table->string('codigo_externo', 120)->nullable()->index();
                $table->string('origem_inicial', 32)->nullable();
                $table->string('hash_payload_local', 64)->nullable();
                $table->string('hash_payload_externo', 64)->nullable();
                $table->dateTime('sincronizado_em')->nullable()->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['loja_id', 'tipo_entidade', 'id_local'], 'ca_map_loja_tipo_local_idx');
                $table->index(['loja_id', 'tipo_entidade', 'id_externo'], 'ca_map_loja_tipo_ext_idx');
            });
        }

        if (!Schema::hasTable('conta_azul_import_batches')) {
            Schema::create('conta_azul_import_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loja_id')->nullable()->index();
                $table->unsignedBigInteger('conexao_id')->nullable()->index();
                $table->string('tipo_entidade', 32)->index();
                $table->string('status', 32)->default('pendente')->index();
                $table->json('parametros_json')->nullable();
                $table->unsignedInteger('total_lidos')->default(0);
                $table->unsignedInteger('total_conciliados')->default(0);
                $table->unsignedInteger('total_pendentes')->default(0);
                $table->unsignedInteger('total_falhas')->default(0);
                $table->dateTime('iniciado_em')->nullable();
                $table->dateTime('finalizado_em')->nullable();
                $table->json('resumo_json')->nullable();
                $table->timestamps();

                $table->foreign('conexao_id')->references('id')->on('conta_azul_conexoes')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('conta_azul_sync_logs')) {
            Schema::create('conta_azul_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loja_id')->nullable()->index();
                $table->string('tipo_entidade', 32)->index();
                $table->unsignedBigInteger('id_local')->nullable()->index();
                $table->string('id_externo', 64)->nullable()->index();
                $table->string('direcao', 16)->index();
                $table->string('status', 32)->index();
                $table->unsignedSmallInteger('tentativa')->default(1);
                $table->text('payload_resumo')->nullable();
                $table->text('resposta_resumo')->nullable();
                $table->string('erro_codigo', 64)->nullable();
                $table->text('erro_mensagem')->nullable();
                $table->dateTime('executado_em')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('conta_azul_reconciliation_states')) {
            Schema::create('conta_azul_reconciliation_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loja_id')->nullable()->index();
                $table->string('recurso', 32);
                $table->string('ultimo_cursor', 512)->nullable();
                $table->dateTime('ultima_data_consulta')->nullable();
                $table->dateTime('ultima_execucao_em')->nullable()->index();
                $table->string('status', 32)->default('ok')->index();
                $table->timestamps();

                $table->unique(['loja_id', 'recurso'], 'ca_reconcile_loja_recurso_unq');
            });
        }

        $this->createStaging('stg_conta_azul_pessoas');
        $this->createStaging('stg_conta_azul_produtos');
        $this->createStaging('stg_conta_azul_vendas');
        $this->createStaging('stg_conta_azul_financeiro');
        $this->createStaging('stg_conta_azul_baixas');
        $this->createStaging('stg_conta_azul_notas');
    }

    public function down(): void
    {
        foreach ([
            'stg_conta_azul_notas',
            'stg_conta_azul_baixas',
            'stg_conta_azul_financeiro',
            'stg_conta_azul_vendas',
            'stg_conta_azul_produtos',
            'stg_conta_azul_pessoas',
            'conta_azul_reconciliation_states',
            'conta_azul_sync_logs',
            'conta_azul_import_batches',
            'conta_azul_mapeamentos',
            'conta_azul_tokens',
            'conta_azul_conexoes',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function createStaging(string $name): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($name) {
            $table->id();
            $table->unsignedBigInteger('loja_id')->nullable()->index();
            $table->string('identificador_externo', 190);
            $table->json('payload_json');
            $table->string('hash_payload', 64)->index();
            $table->string('status_conciliacao', 32)->default('novo')->index();
            $table->text('observacao_conciliacao')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('conta_azul_import_batches')->nullOnDelete();
            $table->unique(['loja_id', 'identificador_externo'], $name . '_loja_ext_unq');
        });
    }
};
