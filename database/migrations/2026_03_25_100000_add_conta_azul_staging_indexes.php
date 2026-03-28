<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tables = [
            'stg_conta_azul_pessoas',
            'stg_conta_azul_produtos',
            'stg_conta_azul_vendas',
            'stg_conta_azul_financeiro',
            'stg_conta_azul_baixas',
            'stg_conta_azul_notas',
        ];

        foreach ($tables as $t) {
            if (!Schema::hasTable($t)) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->index(['loja_id', 'status_conciliacao'], $t . '_ca_loja_status');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'stg_conta_azul_pessoas',
            'stg_conta_azul_produtos',
            'stg_conta_azul_vendas',
            'stg_conta_azul_financeiro',
            'stg_conta_azul_baixas',
            'stg_conta_azul_notas',
        ];

        foreach ($tables as $t) {
            if (!Schema::hasTable($t)) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->dropIndex($t . '_ca_loja_status');
            });
        }
    }
};
