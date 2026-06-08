<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('estoque_movimentacoes', 'ix_em_var_data', function (Blueprint $table) {
            $table->index(['id_variacao', 'data_movimentacao'], 'ix_em_var_data');
        });
        $this->addIndexIfMissing('estoque_movimentacoes', 'ix_em_var_tipo_data', function (Blueprint $table) {
            $table->index(['id_variacao', 'tipo', 'data_movimentacao'], 'ix_em_var_tipo_data');
        });
        $this->addIndexIfMissing('estoque_movimentacoes', 'ix_em_dep_orig_data', function (Blueprint $table) {
            $table->index(['id_deposito_origem', 'data_movimentacao'], 'ix_em_dep_orig_data');
        });
        $this->addIndexIfMissing('estoque_movimentacoes', 'ix_em_dep_dest_data', function (Blueprint $table) {
            $table->index(['id_deposito_destino', 'data_movimentacao'], 'ix_em_dep_dest_data');
        });
        $this->addIndexIfMissing('estoque_movimentacoes', 'ix_em_data_id', function (Blueprint $table) {
            $table->index(['data_movimentacao', 'id'], 'ix_em_data_id');
        });
        $this->addIndexIfMissing('estoque_movimentacoes', 'ix_em_lote_tipo', function (Blueprint $table) {
            $table->index(['lote_id', 'tipo'], 'ix_em_lote_tipo');
        });

        $this->addIndexIfMissing('estoque_reservas', 'ix_er_var_dep_status_expira', function (Blueprint $table) {
            $table->index(['id_variacao', 'id_deposito', 'status', 'data_expira'], 'ix_er_var_dep_status_expira');
        });
        $this->addIndexIfMissing('estoque_reservas', 'ix_er_pedido_status', function (Blueprint $table) {
            $table->index(['pedido_id', 'status'], 'ix_er_pedido_status');
        });

        $this->addIndexIfMissing('estoque', 'ix_estoque_dep_qtd', function (Blueprint $table) {
            $table->index(['id_deposito', 'quantidade'], 'ix_estoque_dep_qtd');
        });
    }

    public function down(): void
    {
        $this->dropIndexIfExists('estoque', 'ix_estoque_dep_qtd');
        $this->dropIndexIfExists('estoque_reservas', 'ix_er_pedido_status');
        $this->dropIndexIfExists('estoque_reservas', 'ix_er_var_dep_status_expira');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_lote_tipo');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_data_id');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_dep_dest_data');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_dep_orig_data');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_var_tipo_data');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_var_data');
    }

    private function addIndexIfMissing(string $tableName, string $indexName, callable $callback): void
    {
        if (!Schema::hasTable($tableName) || $this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, $callback);
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (!Schema::hasTable($tableName) || !$this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
