<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Parseia XML de NFe (modelo 55) e retorna no formato esperado pelo pipeline de importação.
 * Não utiliza o extrator Python; processamento 100% em PHP com suporte a namespace.
 */
class NfeXmlParserService
{
    private const NS_NFE = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * Extrai dados da NFe no formato: pedido, itens, totais.
     *
     * @return array{pedido: array, itens: array, totais: array}
     * @throws InvalidArgumentException quando XML inválido ou sem itens
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

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NS_NFE);

        $pedido = $this->extrairPedido($xpath);
        $itens = $this->extrairItens($xpath);
        $totais = $this->extrairTotais($xpath);

        if (count($itens) === 0) {
            throw new InvalidArgumentException('Nenhum item de produto encontrado na NFe.');
        }

        return [
            'pedido' => $pedido,
            'itens' => $itens,
            'totais' => $totais,
        ];
    }

    private function extrairPedido(DOMXPath $xpath): array
    {
        $dhEmi = $this->texto($xpath, '//nfe:ide/nfe:dhEmi');
        $nNF = $this->texto($xpath, '//nfe:ide/nfe:nNF');
        $chNFe = $this->texto($xpath, '//nfe:infNFe/@Id');
        if ($chNFe !== null && str_starts_with($chNFe, 'NFe')) {
            $chNFe = substr($chNFe, 3);
        }

        $emitente = $this->texto($xpath, '//nfe:emit/nfe:xNome') ?: $this->texto($xpath, '//nfe:emit/nfe:xFant');
        $destinatario = $this->texto($xpath, '//nfe:dest/nfe:xNome');

        $dataPedido = null;
        if ($dhEmi !== null && $dhEmi !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i:sP', $dhEmi)
                ?: \DateTime::createFromFormat('Y-m-d\TH:i:s', substr($dhEmi, 0, 19));
            $dataPedido = $dt ? $dt->format('d/m/Y') : $dhEmi;
        }

        return [
            'numero_pedido' => $nNF ?: $chNFe,
            'data_pedido' => $dataPedido,
            'data_inclusao' => $dataPedido,
            'data_entrega' => null,
            'cliente' => $destinatario ?: $emitente,
            'observacoes' => '',
        ];
    }

    private function extrairItens(DOMXPath $xpath): array
    {
        $dets = $xpath->query('//nfe:det');
        if ($dets === false || $dets->length === 0) {
            return [];
        }

        $itens = [];
        foreach ($dets as $det) {
            $prod = $xpath->query('nfe:prod', $det)->item(0);
            if (!$prod) {
                continue;
            }

            $cProd = $this->nodeText($prod, 'cProd');
            $xProd = $this->nodeText($prod, 'xProd');
            $qCom = $this->nodeText($prod, 'qCom');
            $vUnCom = $this->nodeText($prod, 'vUnCom');
            $vProd = $this->nodeText($prod, 'vProd');
            $cEan = $this->normalizarCodigoBarras(
                $this->nodeText($prod, 'cEAN') ?: $this->nodeText($prod, 'cEANTrib')
            );

            $codigo = $cProd ?: ('ITEM-' . (count($itens) + 1));
            $descricao = $xProd ?: $codigo;
            $qtd = $this->toFloat($qCom, 1.0);
            $vUn = $this->toFloat($vUnCom, 0.0);
            $vTot = $this->toFloat($vProd, $qtd * $vUn);

            $itens[] = [
                'codigo' => $codigo,
                'ref' => $cProd,
                'codigo_barras' => $cEan,
                'nome' => $descricao,
                'descricao' => $descricao,
                'quantidade' => (string) $qtd,
                'unidade' => $this->nodeText($prod, 'uCom') ?: 'UN',
                'preco_unitario' => (string) $vUn,
                'preco' => (string) $vUn,
                'custo_unitario' => (string) $vUn,
                'valor_bruto' => (string) $vTot,
                'valor_total' => (string) $vTot,
                'valor_total_linha' => (string) $vTot,
            ];
        }

        return $itens;
    }

    private function extrairTotais(DOMXPath $xpath): array
    {
        $vNF = $this->texto($xpath, '//nfe:total/nfe:ICMSTot/nfe:vNF');
        $vProd = $this->texto($xpath, '//nfe:total/nfe:ICMSTot/nfe:vProd');

        $total = $vNF ?? $vProd ?? '0';
        $total = str_replace(',', '.', $total);
        $totalBr = str_replace('.', ',', (string) (float) $total);

        return [
            'total_bruto' => $totalBr,
            'total_liquido' => $totalBr,
        ];
    }

    private function texto(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);
        if (!$node) {
            return null;
        }
        $v = trim((string) $node->nodeValue);
        return $v === '' ? null : $v;
    }

    private function nodeText(\DOMNode $context, string $localName): ?string
    {
        $child = null;
        foreach ($context->childNodes as $node) {
            if ($node->localName === $localName || $node->nodeName === $localName) {
                $child = $node;
                break;
            }
        }
        if (!$child) {
            return null;
        }
        $v = trim((string) $child->nodeValue);
        return $v === '' ? null : $v;
    }

    private function toFloat(?string $value, float $default = 0.0): float
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return (float) str_replace(',', '.', $value);
    }

    private function normalizarCodigoBarras(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $codigo = trim($value);
        if ($codigo === '' || strtoupper($codigo) === 'SEM GTIN') {
            return null;
        }

        return $codigo;
    }
}
