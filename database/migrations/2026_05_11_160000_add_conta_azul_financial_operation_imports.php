<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $stagingTables = [
        'stg_conta_azul_parcelas',
        'stg_conta_azul_saldos_contas_financeiras',
        'stg_conta_azul_formas_pagamento',
    ];

    public function up(): void
    {
        foreach ($this->stagingTables as $tableName) {
            $this->createStaging($tableName);
        }

        Schema::table('contas_financeiras', function (Blueprint $table) {
            if (!Schema::hasColumn('contas_financeiras', 'saldo_atual')) {
                $table->decimal('saldo_atual', 14, 2)->nullable()->after('saldo_inicial');
            }
            if (!Schema::hasColumn('contas_financeiras', 'saldo_atual_em')) {
                $table->dateTime('saldo_atual_em')->nullable()->after('saldo_atual')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table) {
            if (Schema::hasColumn('contas_financeiras', 'saldo_atual_em')) {
                $table->dropColumn('saldo_atual_em');
            }
            if (Schema::hasColumn('contas_financeiras', 'saldo_atual')) {
                $table->dropColumn('saldo_atual');
            }
        });

        foreach (array_reverse($this->stagingTables) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }

    private function createStaging(string $tableName): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($tableName) {
            $prefix = $this->indexPrefix($tableName);

            $table->id();
            $table->unsignedBigInteger('loja_id')->nullable();
            $table->string('identificador_externo', 190);
            $table->json('payload_json');
            $table->string('hash_payload', 64);
            $table->string('status_conciliacao', 32)->default('novo');
            $table->text('observacao_conciliacao')->nullable();
            $table->unsignedBigInteger('candidato_id_local')->nullable();
            $table->unsignedTinyInteger('candidato_score')->nullable();
            $table->string('candidato_motivo', 255)->nullable();
            $table->json('candidato_json')->nullable();
            $table->string('conciliacao_origem', 32)->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamps();

            $table->index('loja_id', $prefix . '_loja_idx');
            $table->index('hash_payload', $prefix . '_hash_idx');
            $table->index('status_conciliacao', $prefix . '_status_idx');
            $table->index('candidato_id_local', $prefix . '_cand_id_idx');
            $table->index('candidato_score', $prefix . '_cand_score_idx');
            $table->index('conciliacao_origem', $prefix . '_origem_idx');
            $table->index('batch_id', $prefix . '_batch_idx');
            $table->foreign('batch_id', $prefix . '_batch_fk')->references('id')->on('conta_azul_import_batches')->nullOnDelete();
            $table->unique(['loja_id', 'identificador_externo'], $prefix . '_loja_ext_unq');
        });
    }

    private function indexPrefix(string $tableName): string
    {
        return match ($tableName) {
            'stg_conta_azul_parcelas' => 'stg_ca_parcelas',
            'stg_conta_azul_saldos_contas_financeiras' => 'stg_ca_saldos_contas',
            'stg_conta_azul_formas_pagamento' => 'stg_ca_formas_pgto',
            default => substr($tableName, 0, 32),
        };
    }
};
