<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'pedidos';
    private const UNIQUE_INDEX = 'pedidos_numero_externo_unique';
    private const NORMAL_INDEX = 'pedidos_numero_externo_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->indexExists(self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (! $this->indexExists(self::NORMAL_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('numero_externo', self::NORMAL_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasDuplicatedExternalNumbers()) {
            return;
        }

        if ($this->indexExists(self::NORMAL_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::NORMAL_INDEX);
            });
        }

        if (! $this->indexExists(self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique('numero_externo', self::UNIQUE_INDEX);
            });
        }
    }

    private function hasDuplicatedExternalNumbers(): bool
    {
        return DB::table(self::TABLE)
            ->select('numero_externo')
            ->whereNotNull('numero_externo')
            ->groupBy('numero_externo')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function indexExists(string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('" . self::TABLE . "')"))
                ->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', self::TABLE)
            ->where('index_name', $indexName)
            ->exists();
    }
};
