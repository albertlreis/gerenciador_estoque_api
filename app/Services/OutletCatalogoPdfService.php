<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\ProdutoConjunto;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class OutletCatalogoPdfService
{
    public function __construct(
        private readonly OutletCatalogoPricingService $pricingService,
    ) {
    }

    /**
     * @param Collection<int, Produto>|iterable<Produto> $produtos
     * @return array{conjuntos: array<int, array<string,mixed>>, itens_avulsos: array<int, array<string,mixed>>}
     */
    public function build(iterable $produtos): array
    {
        $produtosCollection = $produtos instanceof Collection ? $produtos : collect($produtos);
        $disponiveis = $this->buildDisponiveisMap($produtosCollection);

        $conjuntosRenderizados = [];
        $variacoesEmConjuntos = [];

        ProdutoConjunto::query()
            ->where('ativo', true)
            ->with([
                'itens:id,produto_conjunto_id,produto_variacao_id,label,ordem',
                'itens.variacao:id,produto_id,referencia,nome,preco',
                'itens.variacao.produto:id,nome',
                'principalVariacao:id,produto_id,referencia,nome,preco',
                'principalVariacao.produto:id,nome',
            ])
            ->orderBy('nome')
            ->get()
            ->each(function (ProdutoConjunto $conjunto) use ($disponiveis, &$conjuntosRenderizados, &$variacoesEmConjuntos): void {
                $card = $this->buildConjuntoCard($conjunto, $disponiveis);
                if ($card === null) {
                    return;
                }

                $conjuntosRenderizados[] = $card;
                foreach ($card['variacao_ids'] as $variacaoId) {
                    $variacoesEmConjuntos[$variacaoId] = true;
                }
            });

        $itensAvulsos = $disponiveis
            ->reject(fn (array $item, int $variacaoId) => isset($variacoesEmConjuntos[$variacaoId]))
            ->values()
            ->map(fn (array $item) => $this->buildAvulsoCard($item))
            ->all();

        return [
            'conjuntos' => $conjuntosRenderizados,
            'itens_avulsos' => $itensAvulsos,
        ];
    }

    /**
     * @param Collection<int, Produto> $produtos
     * @return Collection<int, array<string,mixed>>
     */
    private function buildDisponiveisMap(Collection $produtos): Collection
    {
        return $produtos
            ->flatMap(function (Produto $produto) {
                return ($produto->variacoes ?? collect())->map(function (ProdutoVariacao $variacao) use ($produto) {
                    $variacao->setRelation('produto', $produto);

                    $qtdTotalRestante = (int) collect($variacao->outlets ?? [])->sum('quantidade_restante');
                    if ($qtdTotalRestante <= 0) {
                        return null;
                    }

                    $pricing = $this->pricingService->build(collect([$variacao]));
                    $precoBase = $pricing['preco_venda'];
                    $precoOutlet = $pricing['preco_final_venda'] ?? $pricing['preco_outlet'] ?? $precoBase;
                    $temPrecoOriginal = $precoBase !== null
                        && $precoOutlet !== null
                        && (float) $precoOutlet < (float) $precoBase;

                    return [
                        'variacao_id' => (int) $variacao->id,
                        'produto_id' => (int) $produto->id,
                        'produto_nome' => $produto->nome,
                        'categoria_nome' => $produto->categoria?->nome,
                        'altura' => $produto->altura,
                        'largura' => $produto->largura,
                        'profundidade' => $produto->profundidade,
                        'referencia' => $variacao->referencia,
                        'nome' => $variacao->nome,
                        'nome_completo' => $variacao->nome_completo,
                        'preco' => $precoBase !== null ? (float) $precoBase : null,
                        'preco_outlet' => $precoOutlet !== null ? (float) $precoOutlet : null,
                        'preco_label' => $precoOutlet !== null ? $this->formatMoney((float) $precoOutlet) : '-',
                        'preco_original_label' => $temPrecoOriginal ? $this->formatMoney((float) $precoBase) : null,
                        'percentual_desconto' => (float) ($pricing['percentual_desconto'] ?? 0),
                        'pagamento_label' => $pricing['pagamento_label'] ?? null,
                        'qtd_total_restante' => $qtdTotalRestante,
                        'imagem_src' => $this->resolverImagemSrcVariacao($variacao),
                        'atributos_acabamentos' => $this->mapearAtributos($variacao),
                    ];
                });
            })
            ->filter()
            ->keyBy('variacao_id');
    }

    /**
     * @param Collection<int, array<string,mixed>> $disponiveis
     * @return array<string,mixed>|null
     */
    private function buildConjuntoCard(ProdutoConjunto $conjunto, Collection $disponiveis): ?array
    {
        if (blank($conjunto->hero_image_path)) {
            return null;
        }

        $heroSrc = $this->resolverImagemHeroSrc((string) $conjunto->hero_image_path);
        if ($heroSrc === null) {
            return null;
        }

        $itensDisponiveis = $conjunto->itens
            ->map(function ($item) use ($disponiveis) {
                $disponivel = $disponiveis->get((int) $item->produto_variacao_id);
                if ($disponivel === null) {
                    return null;
                }

                return [
                    'produto_variacao_id' => (int) $item->produto_variacao_id,
                    'label' => $item->label ?: $disponivel['produto_nome'],
                    'referencia' => $disponivel['referencia'],
                    'nome' => $disponivel['nome_completo'] ?: $disponivel['produto_nome'],
                    'preco' => $disponivel['preco'],
                    'preco_outlet' => $disponivel['preco_outlet'],
                    'preco_label' => $disponivel['preco_label'],
                    'preco_original_label' => $disponivel['preco_original_label'],
                    'qtd' => $disponivel['qtd_total_restante'],
                ];
            })
            ->filter()
            ->values();

        if ($itensDisponiveis->isEmpty()) {
            return null;
        }

        $precoModo = (string) $conjunto->preco_modo;
        $precoExibicao = $this->buildPrecoConjunto($precoModo, $conjunto, $itensDisponiveis);

        return [
            'tipo' => 'conjunto',
            'id' => (int) $conjunto->id,
            'nome' => $conjunto->nome,
            'descricao' => $conjunto->descricao,
            'imagem_src' => $heroSrc,
            'preco_modo' => $precoModo,
            'preco_label' => $precoExibicao['label'],
            'preco_original_label' => $precoExibicao['original_label'],
            'preco_valor' => $precoExibicao['valor'],
            'itens' => $itensDisponiveis->all(),
            'variacao_ids' => $itensDisponiveis->pluck('produto_variacao_id')->all(),
        ];
    }

    /**
     * @param Collection<int, array<string,mixed>> $itensDisponiveis
     * @return array{label:string,valor:float|null,original_label:string|null}
     */
    private function buildPrecoConjunto(string $precoModo, ProdutoConjunto $conjunto, Collection $itensDisponiveis): array
    {
        if ($precoModo === 'individual') {
            return [
                'label' => 'Preço por item',
                'valor' => null,
                'original_label' => null,
            ];
        }

        if ($precoModo === 'apartir') {
            $principalId = (int) ($conjunto->principal_variacao_id ?? 0);
            $principal = $itensDisponiveis->firstWhere('produto_variacao_id', $principalId);
            $valor = $principal['preco_outlet'] ?? $itensDisponiveis
                ->pluck('preco_outlet')
                ->filter(fn ($preco) => $preco !== null)
                ->min();
            $valorOriginal = $principal['preco'] ?? $itensDisponiveis
                ->pluck('preco')
                ->filter(fn ($preco) => $preco !== null)
                ->min();

            return [
                'label' => $valor !== null ? 'A partir de ' . $this->formatMoney((float) $valor) : 'A partir de -',
                'valor' => $valor !== null ? (float) $valor : null,
                'original_label' => $valorOriginal !== null && $valor !== null && (float) $valor < (float) $valorOriginal
                    ? 'De ' . $this->formatMoney((float) $valorOriginal)
                    : null,
            ];
        }

        $total = (float) $itensDisponiveis
            ->pluck('preco_outlet')
            ->filter(fn ($preco) => $preco !== null)
            ->sum();
        $totalOriginal = (float) $itensDisponiveis
            ->pluck('preco')
            ->filter(fn ($preco) => $preco !== null)
            ->sum();

        return [
            'label' => $this->formatMoney($total),
            'valor' => $total,
            'original_label' => $totalOriginal > 0 && $total < $totalOriginal ? $this->formatMoney($totalOriginal) : null,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function buildAvulsoCard(array $item): array
    {
        return [
            'tipo' => 'avulso',
            'id' => $item['variacao_id'],
            'produto_id' => $item['produto_id'],
            'nome' => $item['produto_nome'],
            'variacao_nome' => $item['nome_completo'] ?: $item['nome'] ?: $item['produto_nome'],
            'categoria_nome' => $item['categoria_nome'],
            'referencia' => $item['referencia'],
            'altura' => $item['altura'],
            'largura' => $item['largura'],
            'profundidade' => $item['profundidade'],
            'imagem_src' => $item['imagem_src'],
            'preco' => $item['preco_outlet'],
            'preco_label' => $item['preco_label'],
            'preco_original_label' => $item['preco_original_label'],
            'percentual_desconto' => $item['percentual_desconto'],
            'pagamento_label' => $item['pagamento_label'],
            'qtd_total_restante' => $item['qtd_total_restante'],
            'atributos_acabamentos' => $item['atributos_acabamentos'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function mapearAtributos(ProdutoVariacao $variacao): array
    {
        return ($variacao->atributos ?? collect())
            ->map(function ($atributo) {
                $nome = trim((string) ($atributo->atributo_label ?? $atributo->atributo ?? ''));
                $valor = trim((string) ($atributo->valor ?? ''));

                if ($nome === '' && $valor === '') {
                    return null;
                }

                if ($nome === '') {
                    return $valor;
                }

                if ($valor === '') {
                    return $nome;
                }

                return "{$nome}: {$valor}";
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resolverImagemHeroSrc(string $path): ?string
    {
        $publicStorage = public_path('storage/' . ltrim($path, '/'));
        if (is_file($publicStorage)) {
            return $publicStorage;
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        return null;
    }

    private function resolverImagemSrcVariacao(ProdutoVariacao $variacao): ?string
    {
        $url = trim((string) ($variacao->imagem?->url ?? ''));
        if ($url === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim(str_replace('\\', '/', (string) $path), '/');

        if (str_starts_with($path, 'storage/')) {
            $relative = ltrim(substr($path, strlen('storage/')), '/');
            $publicStorage = public_path('storage/' . $relative);
            if (is_file($publicStorage)) {
                return $publicStorage;
            }

            if (Storage::disk('public')->exists($relative)) {
                return Storage::disk('public')->path($relative);
            }
        }

        $publicFile = public_path($path);
        if (is_file($publicFile)) {
            return $publicFile;
        }

        return null;
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
