<?php

namespace App\Services\Import;

use App\Enums\StatusRevisaoCadastro;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\ProdutoVariacaoCodigoHistorico;
use App\Services\ProdutoVariacaoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProdutoUpsertService
{
    public function __construct(
        private readonly ProdutoVariacaoService $variacaoService
    ) {}

    /**
     * @return array{
     *   produto:Produto,
     *   variacao:ProdutoVariacao,
     *   produto_criado:bool,
     *   variacao_criada:bool,
     *   codigos_historicos_criados:int
     * }
     */
    public function upsertProdutoVariacao(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $produtoCriado = false;
            $variacaoCriada = false;
            $codigosHistoricosCriados = 0;

            $attrs = (array) ($payload['atributos'] ?? []);
            $normalizedAttrs = $this->normalizeAttrs($attrs);

            $variacaoExistente = $this->localizarVariacaoExistente($payload);
            $produto = $variacaoExistente?->produto;

            if (!$produto) {
                $produto = $this->localizarProdutoExistente($payload);
            }

            if (!$produto) {
                $produtoCriado = true;
                $produto = Produto::create([
                    'nome' => $payload['nome_limpo'] ?? $payload['nome_completo'] ?? 'Produto',
                    'descricao' => null,
                    'id_categoria' => $payload['categoria_id'] ?? null,
                    'id_fornecedor' => $payload['fornecedor_id'] ?? null,
                    'codigo_produto' => $payload['codigo_produto'] ?? null,
                    'altura' => $payload['a_cm'] ?? null,
                    'largura' => $payload['w_cm'] ?? null,
                    'profundidade' => $payload['p_cm'] ?? null,
                    'peso' => null,
                    'ativo' => true,
                    'manual_conservacao' => null,
                    'estoque_minimo' => null,
                ]);
            }

            $this->atualizarProdutoBase($produto, $payload);

            $variacao = $variacaoExistente ?: $this->localizarVariacaoPorProduto($produto, $payload, $normalizedAttrs);

            if ($variacao) {
                $variacao->fill($this->payloadVariacao($payload, $variacao, $produto->id));
                $variacao->save();
                $this->syncAtributos($variacao->id, $normalizedAttrs);
                $codigosHistoricosCriados = $this->syncCodigosHistoricos($variacao, $payload);

                return [
                    'produto' => $produto->fresh(),
                    'variacao' => $variacao->fresh(['atributos', 'codigosHistoricos']),
                    'produto_criado' => $produtoCriado,
                    'variacao_criada' => false,
                    'codigos_historicos_criados' => $codigosHistoricosCriados,
                ];
            }

            $variacaoCriada = true;
            $variacao = ProdutoVariacao::create([
                'produto_id' => $produto->id,
                ...$this->payloadVariacao($payload, null, $produto->id),
            ]);

            foreach ($normalizedAttrs as $k => $v) {
                ProdutoVariacaoAtributo::create([
                    'id_variacao' => $variacao->id,
                    'atributo' => $k,
                    'valor' => $v,
                ]);
            }

            $codigosHistoricosCriados = $this->syncCodigosHistoricos($variacao, $payload);

            return [
                'produto' => $produto->fresh(),
                'variacao' => $variacao->fresh(['atributos', 'codigosHistoricos']),
                'produto_criado' => $produtoCriado,
                'variacao_criada' => $variacaoCriada,
                'codigos_historicos_criados' => $codigosHistoricosCriados,
            ];
        });
    }

    private function localizarVariacaoExistente(array $payload): ?ProdutoVariacao
    {
        $variacaoIdForcada = (int) ($payload['variacao_id_forcada'] ?? 0);
        $modoCargaInicial = (bool) ($payload['modo_carga_inicial'] ?? false);
        $forcarNovaVariacao = (bool) ($payload['forcar_nova_variacao'] ?? false);
        if ($variacaoIdForcada > 0) {
            $variacao = ProdutoVariacao::with('produto')->find($variacaoIdForcada);
            if ($variacao) {
                return $variacao;
            }
        }

        if ($modoCargaInicial && $forcarNovaVariacao) {
            return null;
        }

        $skuInterno = trim((string) ($payload['sku_interno'] ?? ''));
        if ($skuInterno !== '') {
            $variacao = ProdutoVariacao::with('produto')->where('sku_interno', $skuInterno)->first();
            if ($variacao) {
                return $variacao;
            }
        }

        $chaveVariacao = trim((string) ($payload['chave_variacao'] ?? ''));
        if ($chaveVariacao !== '') {
            $variacao = ProdutoVariacao::with('produto')->where('chave_variacao', $chaveVariacao)->first();
            if ($variacao) {
                return $variacao;
            }
        }

        $referencia = trim((string) ($payload['referencia'] ?? $payload['cod'] ?? ''));
        if ($referencia !== '' && !$modoCargaInicial) {
            return ProdutoVariacao::with('produto')->where('referencia', $referencia)->first();
        }

        return null;
    }

    private function localizarProdutoExistente(array $payload): ?Produto
    {
        $produtoIdForcado = (int) ($payload['produto_id_forcado'] ?? 0);
        $modoCargaInicial = (bool) ($payload['modo_carga_inicial'] ?? false);
        if ($produtoIdForcado > 0) {
            $produto = Produto::find($produtoIdForcado);
            if ($produto) {
                return $produto;
            }
        }

        if ($modoCargaInicial) {
            return null;
        }

        $codigoProduto = trim((string) ($payload['codigo_produto'] ?? ''));
        if ($codigoProduto !== '') {
            $produto = Produto::where('codigo_produto', $codigoProduto)->first();
            if ($produto) {
                return $produto;
            }
        }

        return null;
    }

    private function localizarVariacaoPorProduto(
        Produto $produto,
        array $payload,
        $normalizedAttrs
    ): ?ProdutoVariacao {
        $referencia = trim((string) ($payload['referencia'] ?? $payload['cod'] ?? ''));
        $forcarNovaVariacao = (bool) ($payload['forcar_nova_variacao'] ?? false);

        if ($forcarNovaVariacao) {
            return null;
        }

        if ($referencia !== '') {
            $variacao = ProdutoVariacao::where('produto_id', $produto->id)
                ->where('referencia', $referencia)
                ->first();
            if ($variacao) {
                return $variacao;
            }
        }

        $variacoes = ProdutoVariacao::where('produto_id', $produto->id)->get();
        if ($variacoes->isEmpty()) {
            return null;
        }

        $attrsPorVariacao = ProdutoVariacaoAtributo::whereIn('id_variacao', $variacoes->pluck('id')->all())
            ->get()
            ->groupBy('id_variacao')
            ->map(fn ($items) => $items
                ->mapWithKeys(fn ($a) => [$a->atributo => $a->valor])
                ->sortKeys()
                ->toArray()
            )
            ->toArray();

        $normalizedArray = $normalizedAttrs->sortKeys()->toArray();
        $compareKeys = array_keys($normalizedArray);

        foreach ($variacoes as $variacao) {
            $map = $attrsPorVariacao[(int) $variacao->id] ?? [];

            if (!empty($compareKeys)) {
                $filtered = [];
                foreach ($compareKeys as $k) {
                    if (!array_key_exists($k, $map)) {
                        $filtered = null;
                        break;
                    }
                    $filtered[$k] = $map[$k];
                }

                if ($filtered === null) {
                    continue;
                }

                ksort($filtered);
                if ($filtered === $normalizedArray) {
                    return $variacao;
                }
            } elseif (empty($map)) {
                return $variacao;
            }
        }

        return null;
    }

    private function atualizarProdutoBase(Produto $produto, array $payload): void
    {
        $novoNome = trim((string) ($payload['nome_limpo'] ?? ''));
        if ($novoNome !== '' && ($produto->nome === null || trim((string) $produto->nome) === '' || trim((string) $produto->nome) === 'Produto')) {
            $produto->nome = $novoNome;
        }

        if (!empty($payload['categoria_id']) && empty($produto->id_categoria)) {
            $produto->id_categoria = $payload['categoria_id'];
        }

        if (!empty($payload['fornecedor_id']) && empty($produto->id_fornecedor)) {
            $produto->id_fornecedor = $payload['fornecedor_id'];
        }

        foreach ([
            'codigo_produto' => $payload['codigo_produto'] ?? null,
        ] as $campo => $valor) {
            if (!empty($valor) && empty($produto->{$campo})) {
                $produto->{$campo} = $valor;
            }
        }

        $produto->fill([
            'altura' => $produto->altura ?? ($payload['a_cm'] ?? null),
            'largura' => $produto->largura ?? ($payload['w_cm'] ?? null),
            'profundidade' => $produto->profundidade ?? ($payload['p_cm'] ?? null),
        ])->save();
    }

    private function payloadVariacao(array $payload, ?ProdutoVariacao $variacao, int $produtoId): array
    {
        $modoCargaInicial = (bool) ($payload['modo_carga_inicial'] ?? false);
        $forcarNovaVariacao = (bool) ($payload['forcar_nova_variacao'] ?? false);

        return [
            'referencia' => $this->variacaoService->gerarReferenciaLegadaFallback($payload, $variacao, $produtoId),
            'nome' => $payload['nome_completo'] ?? $payload['nome_limpo'] ?? $variacao?->nome,
            'preco' => $payload['valor'] ?? $variacao?->preco,
            'custo' => $payload['custo'] ?? $variacao?->custo,
            'codigo_barras' => $payload['codigo_barras'] ?? $variacao?->codigo_barras,
            'sku_interno' => $payload['sku_interno'] ?? $variacao?->sku_interno,
            // Carga inicial aceita duplicidades; não força unicidade por chave de variação.
            'chave_variacao' => ($modoCargaInicial && $forcarNovaVariacao)
                ? null
                : ($payload['chave_variacao'] ?? $variacao?->chave_variacao),
            'dimensao_1' => $payload['dimensao_1'] ?? $payload['w_cm'] ?? $variacao?->dimensao_1,
            'dimensao_2' => $payload['dimensao_2'] ?? $payload['p_cm'] ?? $variacao?->dimensao_2,
            'dimensao_3' => $payload['dimensao_3'] ?? $payload['a_cm'] ?? $variacao?->dimensao_3,
            'cor' => $payload['cor'] ?? $payload['cor_extraida'] ?? $variacao?->cor,
            'lado' => $payload['lado'] ?? $payload['lado_extraido'] ?? $variacao?->lado,
            'material_oficial' => $payload['material_oficial'] ?? $variacao?->material_oficial,
            'acabamento_oficial' => $payload['acabamento_oficial'] ?? $variacao?->acabamento_oficial,
            'conflito_codigo' => (bool) ($payload['conflito_codigo'] ?? $variacao?->conflito_codigo ?? false),
            'status_revisao' => $payload['status_revisao']
                ?? $variacao?->status_revisao?->value
                ?? $variacao?->status_revisao
                ?? StatusRevisaoCadastro::NAO_REVISADO->value,
        ];
    }

    private function normalizeAttrs(array $attrs)
    {
        return collect($attrs)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->mapWithKeys(function ($v, $k) {
                $normalizedKey = (string) Str::of((string) $k)->squish()->lower()->ascii();
                $normalizedValue = $this->formatAttrValue($v);
                return [$normalizedKey => $normalizedValue];
            })
            ->sortKeys();
    }

    private function formatAttrValue(mixed $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }

        if (is_float($v)) {
            return rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        }

        if (is_numeric($v) && is_string($v)) {
            $s = trim($v);
            if ($s === '') {
                return '';
            }

            if (str_contains($s, '.') && str_contains($s, ',')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } elseif (str_contains($s, ',')) {
                $s = str_replace(',', '.', $s);
            }

            if (is_numeric($s)) {
                return rtrim(rtrim(sprintf('%.4F', (float) $s), '0'), '.');
            }
        }

        return (string) Str::of((string) $v)->squish();
    }

    private function syncAtributos(int $variacaoId, $normalizedAttrs): void
    {
        foreach ($normalizedAttrs as $k => $v) {
            ProdutoVariacaoAtributo::updateOrCreate(
                ['id_variacao' => $variacaoId, 'atributo' => $k],
                ['valor' => $v]
            );
        }
    }

    private function syncCodigosHistoricos(ProdutoVariacao $variacao, array $payload): int
    {
        $items = $this->normalizarCodigosHistoricos($payload);
        if (empty($items)) {
            return 0;
        }

        $criados = 0;

        foreach ($items as $item) {
            $registro = ProdutoVariacaoCodigoHistorico::firstOrNew(
                [
                    'produto_variacao_id' => $variacao->id,
                    'hash_conteudo' => $item['hash_conteudo'],
                ]
            );

            $novoRegistro = !$registro->exists;
            $registro->fill([
                'codigo' => $item['codigo'],
                'codigo_origem' => $item['codigo_origem'],
                'codigo_modelo' => $item['codigo_modelo'],
                'fonte' => $item['fonte'],
                'aba_origem' => $item['aba_origem'],
                'observacoes' => $item['observacoes'],
                'principal' => $item['principal'],
            ]);
            $registro->save();

            if ($novoRegistro) {
                $criados++;
            }
        }

        $hashPrincipal = $items[0]['hash_conteudo'];

        $variacao->codigosHistoricos()
            ->where('hash_conteudo', '!=', $hashPrincipal)
            ->update(['principal' => false]);

        $variacao->codigosHistoricos()
            ->where('hash_conteudo', $hashPrincipal)
            ->update(['principal' => true]);

        return $criados;
    }

    private function normalizarCodigosHistoricos(array $payload): array
    {
        $items = [];

        if (!empty($payload['codigos_historicos']) && is_array($payload['codigos_historicos'])) {
            $items = $payload['codigos_historicos'];
        } else {
            $codigo = trim((string) ($payload['codigo'] ?? $payload['cod'] ?? ''));
            $codigoOrigem = trim((string) ($payload['codigo_origem'] ?? ''));
            $codigoModelo = trim((string) ($payload['codigo_modelo'] ?? ''));

            if ($codigo !== '' || $codigoOrigem !== '' || $codigoModelo !== '') {
                $items[] = [
                    'codigo' => $codigo,
                    'codigo_origem' => $codigoOrigem,
                    'codigo_modelo' => $codigoModelo,
                    'fonte' => $payload['fonte'] ?? 'importacao',
                    'aba_origem' => $payload['aba_origem'] ?? null,
                    'observacoes' => $payload['observacoes_codigo_historico'] ?? null,
                ];
            }
        }

        $normalizados = [];

        foreach ($items as $index => $item) {
            $codigo = trim((string) ($item['codigo'] ?? ''));
            $codigoOrigem = trim((string) ($item['codigo_origem'] ?? ''));
            $codigoModelo = trim((string) ($item['codigo_modelo'] ?? ''));
            if ($codigo === '' && $codigoOrigem === '' && $codigoModelo === '') {
                continue;
            }

            $hash = sha1(json_encode([
                $codigo !== '' ? $codigo : null,
                $codigoOrigem !== '' ? $codigoOrigem : null,
                $codigoModelo !== '' ? $codigoModelo : null,
                $item['fonte'] ?? null,
                $item['aba_origem'] ?? null,
            ], JSON_UNESCAPED_UNICODE));

            $normalizados[$hash] = [
                'codigo' => $codigo !== '' ? $codigo : null,
                'codigo_origem' => $codigoOrigem !== '' ? $codigoOrigem : null,
                'codigo_modelo' => $codigoModelo !== '' ? $codigoModelo : null,
                'fonte' => ($fonte = trim((string) ($item['fonte'] ?? ''))) !== '' ? $fonte : null,
                'aba_origem' => ($aba = trim((string) ($item['aba_origem'] ?? ''))) !== '' ? $aba : null,
                'observacoes' => ($obs = trim((string) ($item['observacoes'] ?? ''))) !== '' ? $obs : null,
                'principal' => $index === 0,
                'hash_conteudo' => $hash,
            ];
        }

        return array_values($normalizados);
    }
}
