<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProdutoDimensaoLegacyCleanupService
{
    private const AUDIT_TABLE = 'produto_variacao_dimensao_auditorias';

    private const ATTRIBUTE_TARGETS = [
        'dimensao_1' => ['campo' => 'dimensao_1', 'prioridade' => 10],
        'largura_cm' => ['campo' => 'dimensao_1', 'prioridade' => 20],
        'comprimento_cm' => ['campo' => 'dimensao_1', 'prioridade' => 30],
        'diametro_cm' => ['campo' => 'dimensao_1', 'prioridade' => 40],
        'dimensao_2' => ['campo' => 'dimensao_2', 'prioridade' => 10],
        'profundidade_cm' => ['campo' => 'dimensao_2', 'prioridade' => 20],
        'espessura_cm' => ['campo' => 'dimensao_2', 'prioridade' => 30],
        'dimensao_3' => ['campo' => 'dimensao_3', 'prioridade' => 10],
        'altura_cm' => ['campo' => 'dimensao_3', 'prioridade' => 20],
    ];

    public function executar(): array
    {
        $resumo = [
            'analisados' => 0,
            'promovidos' => 0,
            'removidos' => 0,
            'bloqueados' => 0,
            'invalidos' => 0,
        ];

        $atributosBloqueados = DB::table(self::AUDIT_TABLE)
            ->whereIn('acao', ['bloqueado_conflito_alias', 'orfao_sem_variacao', 'valor_invalido'])
            ->pluck('produto_variacao_atributo_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = DB::table('produto_variacao_atributos')->orderBy('id');
        if (!empty($atributosBloqueados)) {
            $query->whereNotIn('id', $atributosBloqueados);
        }

        $atributos = $query
            ->get()
            ->map(function ($atributo) {
                $alvo = $this->resolverAlvo((string) $atributo->atributo);

                return $alvo ? (object) [
                    'id' => (int) $atributo->id,
                    'id_variacao' => (int) $atributo->id_variacao,
                    'atributo' => (string) $atributo->atributo,
                    'valor' => (string) $atributo->valor,
                    'campo' => $alvo['campo'],
                    'prioridade' => $alvo['prioridade'],
                    'decimal' => $this->parseDecimal($atributo->valor),
                ] : null;
            })
            ->filter()
            ->values();

        if ($atributos->isEmpty()) {
            return $resumo;
        }

        DB::transaction(function () use ($atributos, &$resumo) {
            $atributos
                ->groupBy(fn ($atributo) => $atributo->id_variacao . '|' . $atributo->campo)
                ->each(function ($grupo) use (&$resumo) {
                    $resumo['analisados'] += $grupo->count();

                    $variacao = DB::table('produto_variacoes')
                        ->where('id', $grupo->first()->id_variacao)
                        ->first();

                    if (!$variacao) {
                        foreach ($grupo as $atributo) {
                            $this->auditar($atributo, null, null, 'orfao_sem_variacao');
                            $resumo['bloqueados']++;
                        }
                        return;
                    }

                    $validos = $grupo->filter(fn ($atributo) => $atributo->decimal !== null)->values();
                    $invalidos = $grupo->filter(fn ($atributo) => $atributo->decimal === null)->values();

                    foreach ($invalidos as $atributo) {
                        $this->auditar($atributo, $variacao->{$atributo->campo} ?? null, null, 'valor_invalido');
                        $resumo['invalidos']++;
                    }

                    if ($validos->isEmpty()) {
                        return;
                    }

                    $vencedor = $validos
                        ->sortBy([
                            ['prioridade', 'asc'],
                            ['id', 'asc'],
                        ])
                        ->first();

                    $valorAnterior = $this->parseDecimal($variacao->{$vencedor->campo} ?? null);
                    $valorFinal = $vencedor->decimal;
                    $campoFoiAlterado = !$this->decimalIgual($valorAnterior, $valorFinal);

                    if ($campoFoiAlterado) {
                        DB::table('produto_variacoes')
                            ->where('id', $vencedor->id_variacao)
                            ->update([
                                $vencedor->campo => $this->decimalParaBanco($valorFinal),
                                'updated_at' => now(),
                            ]);
                        $resumo['promovidos']++;
                    }

                    foreach ($validos as $atributo) {
                        if (!$this->decimalIgual($atributo->decimal, $valorFinal)) {
                            $this->auditar($atributo, $valorAnterior, $valorFinal, 'bloqueado_conflito_alias');
                            $resumo['bloqueados']++;
                            continue;
                        }

                        $acao = match (true) {
                            (int) $atributo->id === (int) $vencedor->id && $valorAnterior === null => 'preenchido',
                            (int) $atributo->id === (int) $vencedor->id && $campoFoiAlterado => 'corrigido_atributo_venceu',
                            (int) $atributo->id === (int) $vencedor->id => 'confirmado',
                            default => 'removido_redundante',
                        };

                        $this->auditar($atributo, $valorAnterior, $valorFinal, $acao);

                        DB::table('produto_variacao_atributos')
                            ->where('id', $atributo->id)
                            ->delete();

                        $resumo['removidos']++;
                    }
                });
        });

        return $resumo;
    }

    private function resolverAlvo(string $atributo): ?array
    {
        $normalizado = $this->normalizarChave($atributo);

        return self::ATTRIBUTE_TARGETS[$normalizado] ?? null;
    }

    private function normalizarChave(string $valor): string
    {
        $normalizado = (string) Str::of($valor)->squish()->lower()->ascii();
        $normalizado = preg_replace('/[^a-z0-9]+/', '_', $normalizado);

        return trim((string) $normalizado, '_');
    }

    private function parseDecimal(mixed $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }

        $texto = preg_replace('/[^0-9,.\-]+/', '', $texto);
        if ($texto === '' || $texto === '-' || $texto === null) {
            return null;
        }

        if (str_contains($texto, '.') && str_contains($texto, ',')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        } elseif (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', $texto);
        }

        return is_numeric($texto) ? (float) $texto : null;
    }

    private function decimalIgual(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs($a - $b) < 0.005;
    }

    private function decimalParaBanco(?float $valor): ?string
    {
        return $valor === null ? null : number_format($valor, 2, '.', '');
    }

    private function auditar(object $atributo, mixed $valorAnterior, mixed $valorFinal, string $acao): void
    {
        $agora = now();

        DB::table(self::AUDIT_TABLE)->updateOrInsert(
            ['produto_variacao_atributo_id' => $atributo->id],
            [
                'variacao_id' => $atributo->id_variacao,
                'atributo_legado' => $atributo->atributo,
                'valor_legado' => $atributo->valor,
                'campo_destino' => $atributo->campo ?? null,
                'valor_anterior' => $this->decimalParaBanco($this->parseDecimal($valorAnterior)),
                'valor_final' => $this->decimalParaBanco($this->parseDecimal($valorFinal)),
                'acao' => $acao,
                'updated_at' => $agora,
                'created_at' => $agora,
            ]
        );
    }
}
