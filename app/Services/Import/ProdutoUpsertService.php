<?php

namespace App\Services\Import;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use Illuminate\Support\Str;

final class ProdutoUpsertService
{
    /**
     * @return array{produto:Produto, variacao:ProdutoVariacao}
     */
    public function upsertProdutoVariacao(array $payload): array
    {
        // $payload = [
        //   'nome_limpo','nome_completo','categoria_id','w_cm','p_cm','a_cm',
        //   'valor','cod','atributos'=>['madeira'=>..,'tecido_1'=>..,'tecido_2'=>..,'metal_vidro'=>..]
        // ]

        // Produto: usar nome_limpo + categoria; atualizar dimensões quando vierem
        $produto = Produto::firstOrCreate(
            ['nome' => $payload['nome_limpo'] ?? $payload['nome_completo'] ?? '—', 'id_categoria' => $payload['categoria_id'] ?? null],
            [
                'descricao' => null,
                'altura' => $payload['a_cm'] ?? null,
                'largura' => $payload['w_cm'] ?? null,
                'profundidade' => $payload['p_cm'] ?? null,
                'peso' => null,
                'ativo' => true,
                'manual_conservacao' => null,
                'estoque_minimo' => null,
            ]
        );

        // Se dimensões novas são mais completas, atualizar
        $produto->fill([
            'altura' => $payload['a_cm'] ?? $produto->altura,
            'largura' => $payload['w_cm'] ?? $produto->largura,
            'profundidade' => $payload['p_cm'] ?? $produto->profundidade,
        ])->save();

        // Variação por referência (Cod)
        $variacao = ProdutoVariacao::updateOrCreate(
            ['produto_id' => $produto->id, 'referencia' => (string)($payload['cod'] ?? '')],
            ['preco' => $payload['valor'] ?? null, 'nome' => null, 'custo' => null, 'codigo_barras' => null]
        );

        // Atributos normalizados
        $attrs = (array)($payload['atributos'] ?? []);
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') continue;
            ProdutoVariacaoAtributo::updateOrCreate(
                ['id_variacao' => $variacao->id, 'atributo' => (string) Str::of($k)->squish()->lower()],
                ['valor' => (string) Str::of($v)->squish()]
            );
        }

        return compact('produto','variacao');
    }
}
