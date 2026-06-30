<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('localizacoes_estoque')) {
            return;
        }

        DB::transaction(function () {
            $this->limparCodigos();
        });
    }

    public function down(): void
    {
        // Irreversivel: duplicadas podem ter sido mescladas e removidas.
    }

    private function limparCodigos(): void
    {
        $rows = DB::table('localizacoes_estoque')
            ->select([
                'id',
                'deposito_id',
                'area',
                'corredor',
                'setor',
                'coluna',
                'codigo_composto',
                'observacoes',
                'ativo',
            ])
            ->orderBy('deposito_id')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $codigoLimpo = $this->codigoLimpo($row)
                ?? $this->limparCodigoExistente($row->codigo_composto);

            if ($codigoLimpo === null) {
                continue;
            }

            $groups[$row->deposito_id . '|' . $codigoLimpo][] = [
                'row' => $row,
                'codigo_limpo' => $codigoLimpo,
            ];
        }

        foreach ($groups as $items) {
            $collection = collect($items);
            $canonical = $this->canonical($collection);
            $duplicates = $collection
                ->reject(fn ($item) => (int) $item['row']->id === (int) $canonical['row']->id)
                ->values();

            $ativo = $collection->contains(fn ($item) => (bool) $item['row']->ativo);
            $observacoes = $this->observacoesMesclagem($canonical['row'], $duplicates);

            if ($duplicates->isNotEmpty()) {
                $duplicateIds = $duplicates->map(fn ($item) => (int) $item['row']->id)->all();

                if (Schema::hasTable('estoque') && Schema::hasColumn('estoque', 'localizacao_id')) {
                    DB::table('estoque')
                        ->whereIn('localizacao_id', $duplicateIds)
                        ->update(['localizacao_id' => $canonical['row']->id]);
                }

                DB::table('localizacoes_estoque')
                    ->whereIn('id', $duplicateIds)
                    ->delete();
            }

            DB::table('localizacoes_estoque')
                ->where('id', $canonical['row']->id)
                ->update([
                    'codigo_composto' => $canonical['codigo_limpo'],
                    'observacoes' => $observacoes,
                    'ativo' => $ativo,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param Collection<int, array{row: object, codigo_limpo: string}> $items
     * @return array{row: object, codigo_limpo: string}
     */
    private function canonical(Collection $items): array
    {
        return $items
            ->sort(function ($a, $b) {
                $ativo = (int) $b['row']->ativo <=> (int) $a['row']->ativo;

                return $ativo !== 0
                    ? $ativo
                    : (int) $a['row']->id <=> (int) $b['row']->id;
            })
            ->first();
    }

    /**
     * @param Collection<int, array{row: object, codigo_limpo: string}> $duplicates
     */
    private function observacoesMesclagem(object $canonical, Collection $duplicates): ?string
    {
        $base = $this->trimOrNull($canonical->observacoes ?? null);

        if ($duplicates->isEmpty()) {
            return $base;
        }

        $linhas = ['Localizacoes mescladas na limpeza do codigo composto:'];
        foreach ($duplicates as $item) {
            $row = $item['row'];
            $detalhes = $this->detalhesLocalizacao($row);
            $observacoes = $this->trimOrNull($row->observacoes ?? null);

            $linha = '#' . $row->id . ' codigo anterior "' . $row->codigo_composto . '"';
            if ($detalhes !== '') {
                $linha .= ' (' . $detalhes . ')';
            }
            if ($observacoes !== null) {
                $linha .= ' observacoes: ' . str_replace(["\r", "\n"], ' | ', $observacoes);
            }

            $linhas[] = $linha;
        }

        return trim(($base ? $base . "\n" : '') . implode("\n", $linhas));
    }

    private function detalhesLocalizacao(object $row): string
    {
        $detalhes = [];
        foreach (['area', 'corredor', 'setor', 'coluna'] as $campo) {
            $valor = $this->valorFisico($row->{$campo} ?? null);
            if ($valor !== null) {
                $detalhes[] = $campo . ': ' . $valor;
            }
        }

        return implode(', ', $detalhes);
    }

    private function codigoLimpo(object $row): ?string
    {
        $parts = [];
        foreach (['area', 'corredor', 'setor', 'coluna'] as $campo) {
            $valor = $this->valorFisico($row->{$campo} ?? null);
            if ($valor !== null) {
                $parts[] = $valor;
            }
        }

        return empty($parts) ? null : implode('-', $parts);
    }

    private function limparCodigoExistente(mixed $codigo): ?string
    {
        $codigo = $this->trimOrNull($codigo);
        if ($codigo === null) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map(fn ($part) => $this->valorFisico($part), explode('-', $codigo)),
            fn ($part) => $part !== null
        ));

        return empty($parts) ? null : implode('-', $parts);
    }

    private function valorFisico(mixed $value): ?string
    {
        $value = $this->trimOrNull($value);
        if ($value === null || preg_match('/^-+$/', $value)) {
            return null;
        }

        return $value;
    }

    private function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
};
