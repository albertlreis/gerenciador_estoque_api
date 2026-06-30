<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyRows = $this->collectLegacyRows();

        Schema::dropIfExists('localizacao_valores');
        Schema::dropIfExists('localizacao_dimensoes');
        Schema::dropIfExists('localizacoes_estoque');
        Schema::dropIfExists('areas_estoque');

        Schema::create('localizacoes_estoque', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('deposito_id');
            $table->string('area', 80)->nullable();
            $table->string('corredor', 80)->nullable();
            $table->string('setor', 80)->nullable();
            $table->string('coluna', 80)->nullable();
            $table->string('nivel', 80)->nullable();
            $table->string('codigo_composto', 255);
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->timestamps();

            $table->foreign('deposito_id', 'loc_est_deposito_fk')
                ->references('id')
                ->on('depositos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->unique(['deposito_id', 'codigo_composto'], 'loc_est_deposito_codigo_uq');
        });

        if (!Schema::hasColumn('estoque', 'localizacao_id')) {
            Schema::table('estoque', function (Blueprint $table) {
                $table->unsignedBigInteger('localizacao_id')->nullable()->after('id_deposito');
                $table->foreign('localizacao_id', 'estoque_localizacao_fk')
                    ->references('id')
                    ->on('localizacoes_estoque')
                    ->nullOnDelete()
                    ->onUpdate('restrict');
            });
        }

        $this->backfillNewLocations($legacyRows);

        Schema::table('estoque', function (Blueprint $table) {
            foreach (['corredor', 'prateleira', 'nivel'] as $column) {
                if (Schema::hasColumn('estoque', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('estoque', 'localizacao_id')) {
            Schema::table('estoque', function (Blueprint $table) {
                $table->dropForeign('estoque_localizacao_fk');
                $table->dropColumn('localizacao_id');
            });
        }

        Schema::dropIfExists('localizacoes_estoque');

        Schema::create('areas_estoque', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->string('descricao')->nullable();
            $table->timestamps();
        });

        Schema::create('localizacao_dimensoes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 40)->unique();
            $table->string('placeholder', 80)->nullable();
            $table->unsignedInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('localizacoes_estoque', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('estoque_id');
            $table->string('setor', 10)->nullable();
            $table->string('coluna', 10)->nullable();
            $table->string('nivel', 10)->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('codigo_composto', 100)->nullable()->index();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->foreign('estoque_id', 'loc_est_estoque_fk')
                ->references('id')
                ->on('estoque')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('area_id', 'loc_est_area_fk')
                ->references('id')
                ->on('areas_estoque')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->unique('estoque_id', 'loc_est_uq_estoque');
        });

        Schema::create('localizacao_valores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('localizacao_id');
            $table->unsignedBigInteger('dimensao_id');
            $table->string('valor', 30)->nullable();
            $table->timestamps();

            $table->foreign('localizacao_id')->references('id')->on('localizacoes_estoque')->onDelete('cascade');
            $table->foreign('dimensao_id')->references('id')->on('localizacao_dimensoes')->onDelete('cascade');
            $table->unique(['localizacao_id', 'dimensao_id']);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectLegacyRows(): array
    {
        if (!Schema::hasTable('localizacoes_estoque') || !Schema::hasColumn('localizacoes_estoque', 'estoque_id')) {
            return $this->collectLegacyRowsFromEstoqueOnly();
        }

        $dimensionValues = $this->collectDimensionValues();
        $select = [
            'le.id',
            'le.estoque_id',
            'le.setor',
            'le.coluna',
            'le.nivel',
            'le.codigo_composto',
            'le.observacoes',
            'e.id_deposito',
            DB::raw(Schema::hasTable('areas_estoque') ? 'ae.nome as area_nome' : 'NULL as area_nome'),
        ];

        $select[] = Schema::hasColumn('estoque', 'corredor')
            ? 'e.corredor'
            : DB::raw('NULL as corredor');
        $select[] = Schema::hasColumn('estoque', 'prateleira')
            ? 'e.prateleira'
            : DB::raw('NULL as prateleira');
        $select[] = Schema::hasColumn('estoque', 'nivel')
            ? DB::raw('e.nivel as estoque_nivel')
            : DB::raw('NULL as estoque_nivel');

        $query = DB::table('localizacoes_estoque as le')
            ->join('estoque as e', 'e.id', '=', 'le.estoque_id');

        if (Schema::hasTable('areas_estoque') && Schema::hasColumn('localizacoes_estoque', 'area_id')) {
            $query->leftJoin('areas_estoque as ae', 'ae.id', '=', 'le.area_id');
        }

        $rows = $query->select($select)
            ->orderBy('le.id')
            ->get()
            ->map(function ($row) use ($dimensionValues) {
                $values = $dimensionValues[(int) $row->id] ?? [];
                $area = $this->trimOrNull($row->area_nome ?? null) ?? $this->dimensionValue($values, ['area', 'área']);
                $corredor = $this->trimOrNull($row->corredor ?? null) ?? $this->dimensionValue($values, ['corredor']);
                $setor = $this->trimOrNull($row->setor ?? null);
                $coluna = $this->trimOrNull($row->coluna ?? null);
                $nivel = $this->trimOrNull($row->nivel ?? null) ?? $this->dimensionValue($values, ['nivel', 'nível']);
                $observacoes = $this->buildObservacoes(
                    $row->observacoes ?? null,
                    null,
                    $row->prateleira ?? null,
                    $row->estoque_nivel ?? null,
                    $values
                );

                return [
                    'estoque_id' => (int) $row->estoque_id,
                    'deposito_id' => (int) $row->id_deposito,
                    'area' => $area,
                    'corredor' => $corredor,
                    'setor' => $setor,
                    'coluna' => $coluna,
                    'nivel' => $nivel,
                    'codigo_composto' => $this->codigoComposto($area, $corredor, $setor, $coluna, $nivel),
                    'observacoes' => $observacoes,
                ];
            })
            ->all();

        return array_merge($rows, $this->collectLegacyRowsFromEstoqueOnly());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectLegacyRowsFromEstoqueOnly(): array
    {
        if (!Schema::hasTable('estoque')) {
            return [];
        }

        $legacyColumns = array_filter([
            Schema::hasColumn('estoque', 'corredor') ? 'corredor' : null,
            Schema::hasColumn('estoque', 'prateleira') ? 'prateleira' : null,
            Schema::hasColumn('estoque', 'nivel') ? 'nivel' : null,
        ]);

        if (empty($legacyColumns)) {
            return [];
        }

        $select = [
            'e.id as estoque_id',
            'e.id_deposito',
            Schema::hasColumn('estoque', 'corredor') ? 'e.corredor' : DB::raw('NULL as corredor'),
            Schema::hasColumn('estoque', 'prateleira') ? 'e.prateleira' : DB::raw('NULL as prateleira'),
            Schema::hasColumn('estoque', 'nivel') ? 'e.nivel' : DB::raw('NULL as estoque_nivel'),
        ];

        $query = DB::table('estoque as e');
        if (Schema::hasTable('localizacoes_estoque') && Schema::hasColumn('localizacoes_estoque', 'estoque_id')) {
            $query->leftJoin('localizacoes_estoque as le', 'le.estoque_id', '=', 'e.id')
                ->whereNull('le.id');
        }

        return $query->select($select)
            ->get()
            ->map(function ($row) {
                $area = null;
                $corredor = $this->trimOrNull($row->corredor ?? null);
                $setor = null;
                $coluna = null;
                $nivel = $this->trimOrNull($row->estoque_nivel ?? null);

                return [
                    'estoque_id' => (int) $row->estoque_id,
                    'deposito_id' => (int) $row->id_deposito,
                    'area' => $area,
                    'corredor' => $corredor,
                    'setor' => $setor,
                    'coluna' => $coluna,
                    'nivel' => $nivel,
                    'codigo_composto' => $this->codigoComposto($area, $corredor, $setor, $coluna, $nivel),
                    'observacoes' => $this->buildObservacoes(
                        null,
                        null,
                        $row->prateleira ?? null,
                        null,
                        []
                    ),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<int, array{nome:string, valor:?string}>>
     */
    private function collectDimensionValues(): array
    {
        if (!Schema::hasTable('localizacao_valores') || !Schema::hasTable('localizacao_dimensoes')) {
            return [];
        }

        return DB::table('localizacao_valores as lv')
            ->join('localizacao_dimensoes as ld', 'ld.id', '=', 'lv.dimensao_id')
            ->select('lv.localizacao_id', 'ld.nome', 'lv.valor')
            ->get()
            ->groupBy('localizacao_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'nome' => (string) $row->nome,
                'valor' => $this->trimOrNull($row->valor),
            ])->values()->all())
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function backfillNewLocations(array $rows): void
    {
        $locationByKey = [];

        foreach ($rows as $row) {
            if ($row['codigo_composto'] === null) {
                continue;
            }

            $key = $row['deposito_id'] . '|' . $row['codigo_composto'];

            if (!isset($locationByKey[$key])) {
                $locationId = DB::table('localizacoes_estoque')->insertGetId([
                    'deposito_id' => $row['deposito_id'],
                    'area' => $row['area'],
                    'corredor' => $row['corredor'],
                    'setor' => $row['setor'],
                    'coluna' => $row['coluna'],
                    'nivel' => $row['nivel'],
                    'codigo_composto' => $row['codigo_composto'],
                    'observacoes' => $row['observacoes'],
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $locationByKey[$key] = $locationId;
            }

            DB::table('estoque')
                ->where('id', $row['estoque_id'])
                ->update(['localizacao_id' => $locationByKey[$key]]);
        }
    }

    /**
     * @param array<int, array{nome:string, valor:?string}> $values
     */
    private function dimensionValue(array $values, array $names): ?string
    {
        $normalizedNames = array_map(fn ($name) => $this->normalizeName($name), $names);

        foreach ($values as $item) {
            if (in_array($this->normalizeName($item['nome']), $normalizedNames, true)) {
                return $this->trimOrNull($item['valor']);
            }
        }

        return null;
    }

    /**
     * @param array<int, array{nome:string, valor:?string}> $dimensionValues
     */
    private function buildObservacoes(
        ?string $observacoes,
        mixed $localizacaoNivel,
        mixed $prateleira,
        mixed $estoqueNivel,
        array $dimensionValues
    ): ?string {
        $extras = [];
        $nivel = $this->trimOrNull($localizacaoNivel);
        $prat = $this->trimOrNull($prateleira);
        $nivelEstoque = $this->trimOrNull($estoqueNivel);

        if ($nivel !== null) {
            $extras[] = 'Nivel legado: ' . $nivel;
        }
        if ($prat !== null) {
            $extras[] = 'Prateleira legada: ' . $prat;
        }
        if ($nivelEstoque !== null) {
            $extras[] = 'Nivel estoque legado: ' . $nivelEstoque;
        }

        foreach ($dimensionValues as $item) {
            if ($this->dimensionValue([$item], ['area', 'área', 'corredor']) !== null) {
                continue;
            }
            if ($item['valor'] !== null) {
                $extras[] = $item['nome'] . ': ' . $item['valor'];
            }
        }

        $base = $this->trimOrNull($observacoes);
        if (empty($extras)) {
            return $base;
        }

        return trim(($base ? $base . "\n" : '') . implode("\n", $extras));
    }

    private function codigoComposto(
        ?string $area,
        ?string $corredor,
        ?string $setor,
        ?string $coluna,
        ?string $nivel
    ): ?string
    {
        $parts = [
            $this->trimOrNull($area),
            $this->trimOrNull($corredor),
            $this->trimOrNull($setor),
            $this->trimOrNull($coluna),
            $this->trimOrNull($nivel),
        ];

        $parts = array_values(array_filter($parts, fn ($part) => $part !== null));

        if (count($parts) === 0) {
            return null;
        }

        return implode('-', $parts);
    }

    private function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' || preg_match('/^-+$/', $trimmed) ? null : $trimmed;
    }

    private function normalizeName(string $value): string
    {
        return mb_strtolower(trim($value));
    }
};
