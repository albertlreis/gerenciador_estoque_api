<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Parseia XMLs de pedidos de fornecedores no layout LISTING.
 */
class FornecedorPedidoXmlParserService
{
    /**
     * Extrai dados do XML LISTING no contrato esperado pelo preview de pedidos.
     *
     * @return array{pedido: array, itens: array, totais: array}
     */
    public function extrair(UploadedFile $arquivo): array
    {
        $content = file_get_contents($arquivo->getRealPath());
        if ($content === false || trim($content) === '') {
            throw new InvalidArgumentException('Arquivo XML vazio ou não legível.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content);
        libxml_clear_errors();

        if (!$loaded) {
            throw new InvalidArgumentException('Conteúdo não é um XML válido.');
        }

        if (strtoupper($dom->documentElement?->nodeName ?? '') !== 'LISTING') {
            throw new InvalidArgumentException('Layout de XML de fornecedor não suportado.');
        }

        $xpath = new DOMXPath($dom);

        $pedido = $this->extrairPedido($xpath);
        $itens = $this->extrairItens($xpath);
        $totais = $this->extrairTotais($itens);

        return [
            'pedido' => $pedido,
            'itens' => $itens,
            'totais' => $totais,
        ];
    }

    private function extrairPedido(DOMXPath $xpath): array
    {
        $cliente = $this->texto($xpath, '/LISTING/CLIENTE_PEDIDO');
        $ordemCompra = $this->texto($xpath, '/LISTING/ORDEM_COMPRA_PEDIDO');
        $loja = $this->texto($xpath, '/LISTING/LOJA_PEDIDO');
        $fornecedor = $this->texto($xpath, '/LISTING/FORNECEDOR_PEDIDO');

        $observacoes = array_values(array_filter([
            $ordemCompra ? 'Ordem de compra: ' . $ordemCompra : null,
            $loja ? 'Loja: ' . $loja : null,
            $fornecedor ? 'Fornecedor: ' . $fornecedor : null,
        ]));

        return [
            'numero_pedido' => $this->texto($xpath, '/LISTING/NUMERO_PEDIDO'),
            'data_pedido' => null,
            'data_inclusao' => null,
            'data_entrega' => null,
            'cliente' => $cliente ?: $loja ?: $fornecedor ?: 'Fornecedor',
            'observacoes' => implode(' | ', $observacoes),
        ];
    }

    private function extrairItens(DOMXPath $xpath): array
    {
        $items = $xpath->query('/LISTING/ITEMS/ITEM');
        if ($items === false || $items->length === 0) {
            return [];
        }

        $resultado = [];

        foreach ($items as $index => $item) {
            $descricao = trim((string) ($item->attributes?->getNamedItem('DESCRIPTION')?->nodeValue ?? ''));
            $quantidade = $this->toFloat($item->attributes?->getNamedItem('QUANTITY')?->nodeValue ?? null, 0.0);
            $preco = $this->toFloat($item->attributes?->getNamedItem('PRICE')?->nodeValue ?? null, 0.0);

            $codigo = $this->texto($xpath, './REFERENCES/CODE/@REFERENCE', $item);
            $modelo = $this->texto($xpath, './REFERENCES/MODEL/@REFERENCE', $item);
            $valorTotal = $quantidade * $preco;

            $atributos = [];
            if ($modelo) {
                $atributos['modelo_referencia'] = $modelo;
            }

            $resultado[] = [
                'linha' => $index + 1,
                'codigo' => $codigo ?: ('ITEM-' . ($index + 1)),
                'ref' => $codigo,
                'nome' => $descricao !== '' ? $descricao : ($codigo ?: 'ITEM-' . ($index + 1)),
                'descricao' => $descricao !== '' ? $descricao : ($codigo ?: 'ITEM-' . ($index + 1)),
                'quantidade' => (string) $quantidade,
                'unidade' => 'UN',
                'preco_unitario' => (string) $preco,
                'preco' => (string) $preco,
                'custo_unitario' => (string) $preco,
                'valor_bruto' => (string) $valorTotal,
                'valor_total' => (string) $valorTotal,
                'valor_total_linha' => (string) $valorTotal,
                'atributos' => $atributos,
                'atributos_raw' => $modelo ? [['nome' => 'modelo_referencia', 'valor' => $modelo]] : [],
            ];
        }

        return $resultado;
    }

    /**
     * @param list<array<string, mixed>> $itens
     * @return array{total_bruto: string, total_liquido: string}
     */
    private function extrairTotais(array $itens): array
    {
        $total = array_reduce($itens, function (float $carry, array $item): float {
            return $carry + ((float) ($item['valor_total_linha'] ?? 0));
        }, 0.0);

        $totalBr = number_format($total, 2, ',', '');

        return [
            'total_bruto' => $totalBr,
            'total_liquido' => $totalBr,
        ];
    }

    private function texto(DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string
    {
        $node = $xpath->query($query, $context)->item(0);
        if (!$node) {
            return null;
        }

        $valor = trim((string) $node->nodeValue);
        return $valor === '' ? null : $valor;
    }

    private function toFloat(?string $value, float $default = 0.0): float
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return (float) str_replace(',', '.', $value);
    }
}
