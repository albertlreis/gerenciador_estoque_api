<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProdutoVariacaoService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Retorna uma variação completa, com relações necessárias para exibição detalhada.
     */
    public function obterVariacaoCompleta(int $produtoId, int $variacaoId): Builder|array|Collection|Model
    {
        $variacao = ProdutoVariacao::with([
            'produto',
            'atributos',
            'imagem',
            'estoque',
            'outlets',
            'outlets.motivo',
            'outlets.formasPagamento.formaPagamento',
        ])->findOrFail($variacaoId);

        if ($variacao->produto_id !== $produtoId) {
            abort(404, 'Variação não pertence a este produto.');
        }

        return $variacao;
    }

    /**
     * Atualiza ou cria em lote as variações de um produto.
     *
     * @throws ValidationException
     */
    public function atualizarLote(int $produtoId, array $variacoes): void
    {
        DB::transaction(function () use ($produtoId, $variacoes) {
            $produto = Produto::findOrFail($produtoId);
            $idsRecebidos = [];

            foreach ($variacoes as $variacaoData) {
                $this->validarVariacao($variacaoData);

                $existing = null;
                if (!empty($variacaoData['id'])) {
                    $existing = ProdutoVariacao::query()
                        ->where('id', (int) $variacaoData['id'])
                        ->where('produto_id', $produtoId)
                        ->first();
                }

                $before = $existing?->getAttributes() ?? [];

                $variacao = ProdutoVariacao::updateOrCreate(
                    [
                        'id' => $variacaoData['id'] ?? null,
                        'produto_id' => $produtoId,
                    ],
                    [
                        'preco' => $variacaoData['preco'],
                        'custo' => $variacaoData['custo'] ?? null,
                        'referencia' => $variacaoData['referencia'],
                        'codigo_barras' => $variacaoData['codigo_barras'] ?? null,
                    ]
                );

                if ($variacao->wasRecentlyCreated) {
                    $this->auditLogger->logCreate(
                        $variacao,
                        'catalogo',
                        "Variação criada: {$variacao->referencia}",
                        ['produto_id' => $produtoId]
                    );
                } else {
                    $dirty = $this->diffDirty($before, $variacao->getAttributes());
                    $this->auditLogger->logUpdate(
                        $variacao,
                        'catalogo',
                        "Variação atualizada: {$variacao->referencia}",
                        [
                            '__before' => $before,
                            '__dirty' => $dirty,
                            'produto_id' => $produtoId,
                        ]
                    );
                }

                $idsRecebidos[] = $variacao->id;

                if (array_key_exists('atributos', $variacaoData)) {
                    $this->registrarAuditoriaAtributos($variacao, $variacaoData['atributos'] ?? [], $produtoId);
                }
            }

            $removidas = $produto->variacoes()->whereNotIn('id', $idsRecebidos)->get();
            foreach ($removidas as $variacaoRemovida) {
                $this->auditLogger->logDelete(
                    $variacaoRemovida,
                    'catalogo',
                    "Variação removida: {$variacaoRemovida->referencia}",
                    ['produto_id' => $produtoId]
                );
            }

            $produto->variacoes()->whereNotIn('id', $idsRecebidos)->delete();
        });
    }

    /**
     * Atualiza uma variação individual.
     *
     * @throws ValidationException
     */
    public function atualizarIndividual(ProdutoVariacao $variacao, array $data): ProdutoVariacao
    {
        return DB::transaction(function () use ($variacao, $data) {
            Validator::make($data, [
                'preco' => 'sometimes|numeric|min:0',
                'custo' => 'sometimes|nullable|numeric|min:0',
                'referencia' => [
                    'sometimes',
                    'string',
                    'max:255',
                    Rule::unique('produto_variacoes', 'referencia')->ignore($variacao->id),
                ],
                'codigo_barras' => 'sometimes|nullable|string|max:255',
                'atributos' => 'sometimes|array',
                'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
                'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
            ])->validate();

            $before = $variacao->getAttributes();
            $updates = [];

            if (array_key_exists('preco', $data)) {
                $updates['preco'] = $data['preco'];
            }
            if (array_key_exists('custo', $data)) {
                $updates['custo'] = $data['custo'];
            }
            if (array_key_exists('referencia', $data)) {
                $updates['referencia'] = $data['referencia'];
            }
            if (array_key_exists('codigo_barras', $data)) {
                $updates['codigo_barras'] = $data['codigo_barras'];
            }

            if (!empty($updates)) {
                $variacao->fill($updates);
            }

            $dirty = $variacao->getDirty();
            if (!empty($dirty)) {
                $variacao->save();
                $this->auditLogger->logUpdate(
                    $variacao,
                    'catalogo',
                    "Variação atualizada: {$variacao->referencia}",
                    [
                        '__before' => $before,
                        '__dirty' => $dirty,
                        'produto_id' => $variacao->produto_id,
                    ]
                );
            }

            if (array_key_exists('atributos', $data)) {
                $this->registrarAuditoriaAtributos($variacao, $data['atributos'] ?? [], (int) $variacao->produto_id);
            }

            return $variacao->refresh()->load('atributos', 'imagem');
        });
    }

    /**
     * Valida os dados de uma variação.
     *
     * @throws ValidationException
     */
    private function validarVariacao(array $data): void
    {
        Validator::make($data, [
            'id' => 'nullable|integer|exists:produto_variacoes,id',
            'preco' => 'required|numeric|min:0',
            'custo' => 'nullable|numeric|min:0',
            'referencia' => 'required|string|max:255',
            'codigo_barras' => 'nullable|string|max:255',
            'atributos' => 'nullable|array',
            'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
            'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
        ])->validate();
    }

    private function registrarAuditoriaAtributos(ProdutoVariacao $variacao, array $atributosPayload, int $produtoId): void
    {
        $atributosAntes = $variacao->atributos()
            ->orderBy('id')
            ->get(['atributo', 'valor'])
            ->toArray();

        $this->sincronizarAtributos($variacao, $atributosPayload);

        $atributosDepois = $variacao->atributos()
            ->orderBy('id')
            ->get(['atributo', 'valor'])
            ->toArray();

        if (json_encode($atributosAntes) === json_encode($atributosDepois)) {
            return;
        }

        $this->auditLogger->logCustom(
            'ProdutoVariacao',
            $variacao->id,
            'catalogo',
            'UPDATE',
            "Atributos da variação atualizados: {$variacao->referencia}",
            [
                'atributos' => [
                    'old' => $atributosAntes,
                    'new' => $atributosDepois,
                ],
            ],
            [
                'produto_id' => $produtoId,
            ]
        );
    }

    /**
     * Sincroniza os atributos de uma variação com base nos dados recebidos.
     */
    private function sincronizarAtributos(ProdutoVariacao $variacao, array $atributosRecebidos): void
    {
        $atributos = [];

        foreach ($atributosRecebidos as $attr) {
            if (!empty($attr['atributo']) && !empty($attr['valor'])) {
                $atributos[$attr['atributo']] = ['valor' => $attr['valor']];
            }
        }

        $existentes = $variacao->atributos->keyBy('atributo');

        foreach ($atributos as $atributo => $dados) {
            $variacao->atributos()->updateOrCreate(
                ['atributo' => $atributo],
                ['valor' => $dados['valor']]
            );
        }

        $chavesRecebidas = array_keys($atributos);

        $existentes->each(function ($item) use ($chavesRecebidas) {
            if (!in_array($item->atributo, $chavesRecebidas, true)) {
                $item->delete();
            }
        });
    }

    private function diffDirty(array $before, array $after): array
    {
        $dirty = [];
        foreach ($after as $field => $value) {
            $anterior = Arr::get($before, $field);
            if ((string) $anterior !== (string) $value) {
                $dirty[$field] = $value;
            }
        }

        return $dirty;
    }
}
