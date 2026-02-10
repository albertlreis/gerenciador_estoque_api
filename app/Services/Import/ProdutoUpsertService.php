<?php

namespace App\Services\Import;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProdutoUpsertService
{
    /**
     * @return array{produto:Produto, variacao:ProdutoVariacao}
     */
    public function upsertProdutoVariacao(array $payload): array
    {
        return DB::transaction(function () use ($payload) {

            /**
             * Regras:
             *
             * 1) Quando cod/referencia vier preenchido:
             *    - Produto é identificado EXCLUSIVAMENTE por essa referência.
             *    - Mesmo cod → nunca cria novo produto (pode criar nova variação por atributos).
             *
             * 2) Quando cod/referencia vier vazio:
             *    - Criamos uma referência determinística baseada em (categoria + nome_limpo),
             *      evitando colidir tudo em referencia "" e evitar "produto fantasma".
             *
             * 3) Atributos determinam se cria nova variação (mesma referencia pode ter várias variações).
             */

            $codOriginal = trim((string)($payload['cod'] ?? ''));
            $referencia = $codOriginal !== '' ? $codOriginal : $this->makeReferenciaSemCod($payload);

            $attrs = (array)($payload['atributos'] ?? []);

            // --------------------------
            // LOCALIZAR / CRIAR PRODUTO
            // --------------------------
            $variacaoExistente = ProdutoVariacao::where('referencia', $referencia)->first();

            if ($variacaoExistente) {
                $produto = $variacaoExistente->produto;
            } else {
                $produto = Produto::create([
                    'nome' => $payload['nome_limpo'] ?? $payload['nome_completo'] ?? 'Produto',
                    'id_categoria' => $payload['categoria_id'] ?? null,
                    'descricao' => null,
                    'altura' => $payload['a_cm'] ?? null,
                    'largura' => $payload['w_cm'] ?? null,
                    'profundidade' => $payload['p_cm'] ?? null,
                    'peso' => null,
                    'ativo' => true,
                    'manual_conservacao' => null,
                    'estoque_minimo' => null,
                ]);
            }

            // Atualizar nome/categoria (sem sobrescrever nome bom por nome ruim)
            $novoNome = trim((string)($payload['nome_limpo'] ?? ''));
            if (
                $novoNome !== '' &&
                ($produto->nome === null || trim((string)$produto->nome) === '' || trim((string)$produto->nome) === 'Produto')
            ) {
                $produto->nome = $novoNome;
            }

            if (!empty($payload['categoria_id']) && empty($produto->id_categoria)) {
                $produto->id_categoria = $payload['categoria_id'];
            }

            /**
             * Dimensões: NÃO sobrescrever dimensões já preenchidas por null / valores ausentes.
             * Regra conservadora: só preencher se ainda estiver vazio no produto.
             */
            $produto->fill([
                'altura' => $produto->altura ?? ($payload['a_cm'] ?? null),
                'largura' => $produto->largura ?? ($payload['w_cm'] ?? null),
                'profundidade' => $produto->profundidade ?? ($payload['p_cm'] ?? null),
            ])->save();

            // --------------------------
            // NORMALIZAR ATRIBUTOS
            // --------------------------
            $normalizedAttrs = $this->normalizeAttrs($attrs);

            // Buscar variações com essa referencia dentro do mesmo produto
            $variacoesMesmoRef = ProdutoVariacao::where('referencia', $referencia)
                ->where('produto_id', $produto->id)
                ->get();

            // Pré-carregar atributos para evitar N+1
            $attrsPorVariacao = [];
            if ($variacoesMesmoRef->isNotEmpty()) {
                $ids = $variacoesMesmoRef->pluck('id')->all();

                $attrsPorVariacao = ProdutoVariacaoAtributo::whereIn('id_variacao', $ids)
                    ->get()
                    ->groupBy('id_variacao')
                    ->map(function ($items) {
                        return $items
                            ->mapWithKeys(fn ($a) => [$a->atributo => $a->valor])
                            ->sortKeys()
                            ->toArray();
                    })
                    ->toArray();
            }

            // --------------------------
            // ENCONTRAR VARIAÇÃO COM MESMOS ATRIBUTOS (SEM SER AFETADO POR ATRIBUTOS EXTRAS)
            // --------------------------
            $variacaoEncontrada = null;
            $normalizedArray = $normalizedAttrs->sortKeys()->toArray();
            $compareKeys = array_keys($normalizedArray);

            foreach ($variacoesMesmoRef as $var) {
                $map = $attrsPorVariacao[(int)$var->id] ?? [];

                // considera apenas as chaves que vieram no payload (ignora atributos extras manuais)
                if (!empty($compareKeys)) {
                    $filtered = [];
                    foreach ($compareKeys as $k) {
                        if (!array_key_exists($k, $map)) {
                            $filtered = null; // falta chave necessária
                            break;
                        }
                        $filtered[$k] = $map[$k];
                    }
                    if ($filtered === null) continue;

                    ksort($filtered);

                    if ($filtered === $normalizedArray) {
                        $variacaoEncontrada = $var;
                        break;
                    }
                } else {
                    // sem atributos: match na primeira variação sem atributos também
                    if (empty($map)) {
                        $variacaoEncontrada = $var;
                        break;
                    }
                }
            }

            // --------------------------
            // SE ENCONTROU → ATUALIZA
            // --------------------------
            if ($variacaoEncontrada) {
                $variacaoEncontrada->update([
                    'preco' => $payload['valor'] ?? $variacaoEncontrada->preco,
                ]);

                $this->syncAtributos($variacaoEncontrada->id, $normalizedAttrs);

                return [
                    'produto' => $produto,
                    'variacao' => $variacaoEncontrada,
                ];
            }

            // --------------------------
            // NÃO ACHOU → CRIA NOVA VARIAÇÃO
            // --------------------------
            $variacao = ProdutoVariacao::create([
                'produto_id' => $produto->id,
                'referencia' => $referencia,
                'preco' => $payload['valor'] ?? null,
                'nome' => null,
                'custo' => null,
                'codigo_barras' => null,
            ]);

            foreach ($normalizedAttrs as $k => $v) {
                ProdutoVariacaoAtributo::create([
                    'id_variacao' => $variacao->id,
                    'atributo' => $k,
                    'valor' => $v,
                ]);
            }

            return compact('produto', 'variacao');
        });
    }

    private function makeReferenciaSemCod(array $payload): string
    {
        $nome = (string)($payload['nome_limpo'] ?? $payload['nome_completo'] ?? '');
        $nome = (string) Str::of($nome)->squish()->lower()->ascii();

        $cat = (string)($payload['categoria_id'] ?? '0');

        $base = $cat . '|' . $nome;
        if ($base === '0|') {
            $base = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        return 'SC-' . substr(sha1($base), 0, 20);
    }

    private function normalizeAttrs(array $attrs)
    {
        return collect($attrs)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->mapWithKeys(function ($v, $k) {
                $normalizedKey = (string) Str::of((string)$k)->squish()->lower()->ascii();
                $normalizedValue = $this->formatAttrValue($v);
                return [$normalizedKey => $normalizedValue];
            })
            ->sortKeys();
    }

    private function formatAttrValue(mixed $v): string
    {
        if (is_int($v)) return (string)$v;

        if (is_float($v)) {
            $s = rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
            return $s;
        }

        if (is_numeric($v) && is_string($v)) {
            $s = trim($v);
            if ($s === '') return '';

            if (str_contains($s, '.') && str_contains($s, ',')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } elseif (str_contains($s, ',')) {
                $s = str_replace(',', '.', $s);
            }

            if (is_numeric($s)) {
                $f = (float)$s;
                $s = rtrim(rtrim(sprintf('%.4F', $f), '0'), '.');
                return $s;
            }
        }

        return (string) Str::of((string)$v)->squish();
    }

    private function syncAtributos(int $variacaoId, $normalizedAttrs): void
    {
        /**
         * Importação NÃO remove atributos antigos para não apagar atributos manuais
         * e para suportar arquivos "mais pobres" sem perda.
         * (A comparação de match já ignora atributos extras.)
         */
        foreach ($normalizedAttrs as $k => $v) {
            ProdutoVariacaoAtributo::updateOrCreate(
                ['id_variacao' => $variacaoId, 'atributo' => $k],
                ['valor' => $v]
            );
        }
    }
}
