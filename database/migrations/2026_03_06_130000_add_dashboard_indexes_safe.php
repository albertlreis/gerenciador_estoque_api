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
        $this->dropIndexSafely('pedidos', 'idx_pedidos_data_pedido');
        $this->dropIndexSafely('pedidos', 'idx_pedidos_usuario_data');
        $this->dropIndexSafely('pedido_status_historico', 'idx_psh_pedido_id_id');
        $this->dropIndexSafely('pedido_itens', 'idx_pedido_itens_entrega_pendente');
        $this->dropIndexSafely('consignacoes', 'idx_consignacoes_status_prazo_pedido');
        $this->dropIndexSafely('estoque_movimentacoes', 'ix_em_tipo_data');
        $this->dropIndexSafely('estoque_movimentacoes', 'ix_em_data_id');
        $this->dropIndexSafely('estoque_movimentacoes', 'ix_em_dep_orig_data');
        $this->dropIndexSafely('estoque_movimentacoes', 'ix_em_dep_dest_data');
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

    private function addIndexByNameIfMissing(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table) || $this->hasIndexByName($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexSafely(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table) || !$this->hasIndexByName($table, $indexName)) {
            return;
        }

        $indexColumns = $this->getIndexColumns($table, $indexName);

        if ($indexColumns === []) {
            return;
        }

        foreach ($this->getForeignKeys($table) as $foreignKey) {
            $fkColumns = $foreignKey['columns'];

            // Se o índice que vamos remover começa pelas colunas da FK,
            // ele pode estar sendo usado para sustentá-la.
            if (!$this->startsWithColumns($indexColumns, $fkColumns)) {
                continue;
            }

            // Se já existe outro índice que atende essa FK, seguimos.
            if ($this->hasIndexByColumnPrefix($table, $fkColumns, [$indexName])) {
                continue;
            }

            // Caso contrário, criamos um índice simples/de suporte antes do drop.
            $supportIndexName = $this->makeForeignKeySupportIndexName($table, $fkColumns);
            $this->addIndexByNameIfMissing($table, $fkColumns, $supportIndexName);
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

    private function hasIndexByColumnPrefix(string $table, array $columns, array $ignoreIndexNames = []): bool
    {
        $target = array_values(array_map(fn ($value) => strtolower((string) $value), $columns));
        $ignore = array_map(fn ($value) => strtolower((string) $value), $ignoreIndexNames);

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

        $grouped = $rows
            ->reject(fn ($row) => in_array(strtolower((string) $row->INDEX_NAME), $ignore, true))
            ->groupBy('INDEX_NAME');

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

    private function getIndexColumns(string $table, string $indexName): array
    {
        return DB::table('information_schema.statistics')
            ->select(['SEQ_IN_INDEX', 'COLUMN_NAME'])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->orderBy('SEQ_IN_INDEX')
            ->get()
            ->pluck('COLUMN_NAME')
            ->map(fn ($value) => strtolower((string) $value))
            ->values()
            ->all();
    }

    private function getForeignKeys(string $table): array
    {
        $rows = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select([
                'CONSTRAINT_NAME',
                'ORDINAL_POSITION',
                'COLUMN_NAME',
                'REFERENCED_TABLE_NAME',
            ])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->orderBy('CONSTRAINT_NAME')
            ->orderBy('ORDINAL_POSITION')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $foreignKeys = [];

        foreach ($rows->groupBy('CONSTRAINT_NAME') as $constraintName => $constraintRows) {
            $foreignKeys[] = [
                'name' => (string) $constraintName,
                'columns' => $constraintRows
                    ->sortBy('ORDINAL_POSITION')
                    ->pluck('COLUMN_NAME')
                    ->map(fn ($value) => strtolower((string) $value))
                    ->values()
                    ->all(),
            ];
        }

        return $foreignKeys;
    }

    private function startsWithColumns(array $indexColumns, array $targetColumns): bool
    {
        $indexColumns = array_values(array_map(fn ($value) => strtolower((string) $value), $indexColumns));
        $targetColumns = array_values(array_map(fn ($value) => strtolower((string) $value), $targetColumns));

        if (count($indexColumns) < count($targetColumns)) {
            return false;
        }

        return array_slice($indexColumns, 0, count($targetColumns)) === $targetColumns;
    }

    private function makeForeignKeySupportIndexName(string $table, array $columns): string
    {
        $normalizedTable = preg_replace('/[^a-z0-9_]/i', '', strtolower($table)) ?: 'tbl';

        $normalizedColumns = array_map(function ($column) {
            $value = preg_replace('/[^a-z0-9_]/i', '', strtolower((string) $column)) ?: 'col';

            return substr($value, 0, 12);
        }, $columns);

        $base = 'idx_fksp_' . substr($normalizedTable, 0, 20) . '_' . implode('_', $normalizedColumns);
        $hash = substr(md5($table . '|' . implode('|', $columns)), 0, 8);

        return substr($base . '_' . $hash, 0, 64);
    }
};
