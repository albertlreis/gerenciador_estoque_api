<?php

namespace App\Services\Parsers;

use App\Models\ProdutoVariacao;
use Smalot\PdfParser\Parser;
use Illuminate\Http\UploadedFile;
use App\Traits\ExtracaoClienteTrait;
use App\Traits\ExtracaoProdutoTrait;

/**
 * Classe responsável por extrair dados de um arquivo PDF de pedido.
 */
class PedidoPDFParser
{
    use ExtracaoClienteTrait, ExtracaoProdutoTrait;

    /**
     * Faz o parse do arquivo PDF e retorna dados estruturados.
     *
     * @param UploadedFile $arquivo
     * @return array
     * @throws \Exception
     */
    public function parse(UploadedFile $arquivo): array
    {
        $parser = new Parser();
        $texto = $parser->parseFile($arquivo->getRealPath())->getText();

        $cliente = $this->extrairCliente($texto);
        $itens = $this->extrairItens($texto);
        $pedido = $this->extrairPedido($texto, $itens);

        $itensComVariacao = collect($itens)->map(function ($item) {
            $variacao = ProdutoVariacao::with('produto')
                ->where('referencia', $item['ref'] ?? '')
                ->first();

            return array_merge($item, [
                'id_variacao' => $variacao?->id,
                'produto_id' => $variacao?->produto_id,
                'variacao_nome' => $variacao?->descricao,
                'id_categoria' => $variacao?->produto?->id_categoria,
                'nome' => $variacao?->produto?->nome ?? $item['nome'],
            ]);
        });

        return [
            'cliente' => $cliente,
            'pedido' => $pedido,
            'itens' => $itensComVariacao,
        ];
    }
}
