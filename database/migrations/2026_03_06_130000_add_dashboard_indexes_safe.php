<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('pedidos', ['data_pedido'], 'idx_pedidos_data_pedido');
        $this->addIndexIfMissing('pedidos', ['id_usuario', 'data_pedido'], 'idx_pedidos_usuario_data');

        $this->addIndexIfMissing('pedido_status_historico', ['pedido_id', 'id'], 'idx_psh_pedido_id_id');

        $this->addIndexIfMissing(
            'pedido_itens',
            ['entrega_pendente', 'data_liberacao_entrega', 'id_pedido'],
            'idx_pedido_itens_entrega_pendente'
        );

        $this->addIndexIfMissing(
            'consignacoes',
            ['status', 'prazo_resposta', 'pedido_id'],
            'idx_consignacoes_status_prazo_pedido'
        );

        $this->addIndexIfMissing('estoque_movimentacoes', ['tipo', 'data_movimentacao'], 'ix_em_tipo_data');
        $this->addIndexIfMissing('estoque_movimentacoes', ['data_movimentacao', 'id'], 'ix_em_data_id');
        $this->addIndexIfMissing('estoque_movimentacoes', ['id_deposito_origem', 'data_movimentacao'], 'ix_em_dep_orig_data');
        $this->addIndexIfMissing('estoque_movimentacoes', ['id_deposito_destino', 'data_movimentacao'], 'ix_em_dep_dest_data');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('pedidos', 'idx_pedidos_data_pedido');
        $this->dropIndexIfExists('pedidos', 'idx_pedidos_usuario_data');
        $this->dropIndexIfExists('pedido_status_historico', 'idx_psh_pedido_id_id');
        $this->dropIndexIfExists('pedido_itens', 'idx_pedido_itens_entrega_pendente');
        $this->dropIndexIfExists('consignacoes', 'idx_consignacoes_status_prazo_pedido');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_tipo_data');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_data_id');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_dep_orig_data');
        $this->dropIndexIfExists('estoque_movimentacoes', 'ix_em_dep_dest_data');
    }

    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table) || $this->hasIndexByColumnPrefix($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table) || !$this->hasIndexByName($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    private function hasIndexByName(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    private function hasIndexByColumnPrefix(string $table, array $columns): bool
    {
        $target = array_values(array_map('strtolower', $columns));

        $rows = DB::table('information_schema.statistics')
            ->select(['INDEX_NAME', 'SEQ_IN_INDEX', 'COLUMN_NAME'])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get();

        if ($rows->isEmpty()) {
            return false;
        }

        $grouped = $rows->groupBy('INDEX_NAME');

        foreach ($grouped as $indexRows) {
            $indexColumns = $indexRows
                ->sortBy('SEQ_IN_INDEX')
                ->pluck('COLUMN_NAME')
                ->map(fn ($value) => strtolower((string) $value))
                ->values()
                ->all();

            if (count($indexColumns) < count($target)) {
                continue;
            }

            if (array_slice($indexColumns, 0, count($target)) === $target) {
                return true;
            }
        }

        return false;
    }
};
