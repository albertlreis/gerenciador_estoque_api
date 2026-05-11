<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'stg_conta_azul_pessoas',
        'stg_conta_azul_produtos',
        'stg_conta_azul_vendas',
        'stg_conta_azul_financeiro',
        'stg_conta_azul_contas_pagar',
        'stg_conta_azul_baixas',
        'stg_conta_azul_notas',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'candidato_id_local')) {
                    $table->unsignedBigInteger('candidato_id_local')->nullable()->after('observacao_conciliacao')->index();
                }
                if (!Schema::hasColumn($tableName, 'candidato_score')) {
                    $table->unsignedTinyInteger('candidato_score')->nullable()->after('candidato_id_local')->index();
                }
                if (!Schema::hasColumn($tableName, 'candidato_motivo')) {
                    $table->string('candidato_motivo', 255)->nullable()->after('candidato_score');
                }
                if (!Schema::hasColumn($tableName, 'candidato_json')) {
                    $table->json('candidato_json')->nullable()->after('candidato_motivo');
                }
                if (!Schema::hasColumn($tableName, 'conciliacao_origem')) {
                    $table->string('conciliacao_origem', 32)->nullable()->after('candidato_json')->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['conciliacao_origem', 'candidato_json', 'candidato_motivo', 'candidato_score', 'candidato_id_local'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
