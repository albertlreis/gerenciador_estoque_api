<?php

namespace App\Services\Import;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

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
             * 1. Produto é identificado EXCLUSIVAMENTE pelo código (cod)
             * 2. Mesmo cod → nunca cria novo produto
             * 3. Atributos determinam se criará nova variação
             */

            $cod = (string)($payload['cod'] ?? '');
            $attrs = (array)($payload['atributos'] ?? []);

//            if ($cod === '') {
//                throw new Exception("Produto sem código (cod).");
//            }

            /**
             * --------------------------
             *   LOCALIZAR / CRIAR PRODUTO
             * --------------------------
             * Procuramos primeiro variações pelo cod.
             */
            $variacaoExistente = ProdutoVariacao::where('referencia', $cod)->first();

            if ($variacaoExistente) {
                $produto = $variacaoExistente->produto;
            } else {
                // Criar novo produto sem depender de nome
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

            /**
             * Atualizar dimensões caso venham preenchidas
             */
            $produto->fill([
                'altura' => $payload['a_cm'] ?? $produto->altura,
                'largura' => $payload['w_cm'] ?? $produto->largura,
                'profundidade' => $payload['p_cm'] ?? $produto->profundidade,
            ])->save();

            /**
             * --------------------------
             *   VERIFICAR SE ATRIBUTOS JÁ EXISTEM
             * --------------------------
             * Para mesma referência, podem existir várias variações,
             * dependendo dos atributos.
             */

            // Normalizar atributos recebidos
            $normalizedAttrs = collect($attrs)
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->mapWithKeys(function ($v, $k) {
                    $normalizedKey = (string) Str::of($k)->squish()->lower();
                    $normalizedValue = (string) Str::of($v)->squish();

                    return [
                        $normalizedKey => $normalizedValue
                    ];
                });

            // Buscar variações com esse cod
            $variacoesMesmoCod = ProdutoVariacao::where('referencia', $cod)
                ->where('produto_id', $produto->id)
                ->get();

            /** Tentar encontrar uma variação com mesmos atributos */
            $variacaoEncontrada = null;

            foreach ($variacoesMesmoCod as $var) {
                $atributos = ProdutoVariacaoAtributo::where('id_variacao', $var->id)->get();

                // Transformar em map para comparação
                $map = $atributos->mapWithKeys(fn ($a) => [$a->atributo => $a->valor]);

                if ($map->toArray() === $normalizedAttrs->toArray()) {
                    $variacaoEncontrada = $var;
                    break;
                }
            }

            /**
             * --------------------------
             *   SE ENCONTROU VARIAÇÃO → ATUALIZA
             * --------------------------
             */
            if ($variacaoEncontrada) {
                $variacaoEncontrada->update([
                    'preco' => $payload['valor'] ?? $variacaoEncontrada->preco,
                ]);

                // Garantir atributos atualizados
                foreach ($normalizedAttrs as $k => $v) {
                    ProdutoVariacaoAtributo::updateOrCreate(
                        ['id_variacao' => $variacaoEncontrada->id, 'atributo' => $k],
                        ['valor' => $v]
                    );
                }

                return [
                    'produto' => $produto,
                    'variacao' => $variacaoEncontrada,
                ];
            }

            /**
             * --------------------------
             *   NÃO ACHOU → CRIA NOVA VARIAÇÃO
             * --------------------------
             */

            $variacao = ProdutoVariacao::create([
                'produto_id' => $produto->id,
                'referencia' => $cod,
                'preco' => $payload['valor'] ?? null,
                'nome' => null,
                'custo' => null,
                'codigo_barras' => null,
            ]);

            // Criar atributos
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
}
