<?php

namespace App\Traits;

/**
 * Trait com métodos auxiliares para extrair dados de produtos, medidas e atributos do texto.
 */
trait ExtracaoProdutoTrait
{
    /**
     * Extrai os itens de pedido a partir do texto completo.
     *
     * @param string $texto
     * @return array
     */
    protected function extrairItens(string $texto): array
    {
        $linhas = explode("\n", $texto);
        $itens = [];

        foreach ($linhas as $linha) {
            if (!preg_match('/\b\d+\b.*?x/i', $linha)) {
                continue;
            }

            $descricao = trim($linha);
            $produto = $this->extrairProduto($descricao);

            $itens[] = [
                'descricao' => $descricao,
                'quantidade' => $this->extrairQuantidade($descricao),
                'valor' => $this->extrairPreco($descricao),
                'ref' => $this->extrairReferencia($descricao),
                'nome' => $produto['nome'],
                'fixos' => $produto['fixos'],
                'atributos' => $produto['atributos'],
            ];
        }

        return $itens;
    }

    /**
     * Extrai os dados gerais do pedido a partir do texto.
     *
     * @param string $texto
     * @param array $itens
     * @return array
     */
    protected function extrairPedido(string $texto, array $itens): array
    {
        return [
            'numero_externo' => $this->extrairValor('/PEDIDO N.? ?(\d+)/i', $texto),
            'prazo_entrega' => $this->extrairValor('/PRAZO DE ENTREGA\\s+(.+)/i', $texto),
            'forma_pagamento' => $this->extrairValor('/FORMA DE PAGAMENTO\\s+(.+)/i', $texto),
            'vendedor' => $this->extrairValor('/VEND(?:EDOR|A)\\s+(.+)/i', $texto),
            'total' => array_sum(array_map(fn($item) => $item['quantidade'] * $item['valor'], $itens)),
            'observacoes' => null,
        ];
    }

    /**
     * Extrai o nome e atributos do produto a partir da descrição completa.
     *
     * @param string $descricao
     * @return array
     */
    protected function extrairProduto(string $descricao): array
    {
        $nome = $this->extrairNomeProduto($descricao);
        $medidas = $this->extrairMedidas($descricao);
        $atributos = $this->extrairAtributos($descricao, $nome);

        return [
            'nome' => $nome,
            'fixos' => $medidas,
            'atributos' => $atributos,
        ];
    }

    /**
     * Extrai nome base do produto.
     *
     * @param string $descricao
     * @return string
     */
    protected function extrairNomeProduto(string $descricao): string
    {
        if (preg_match('/^(.*?)\s+\d{1,3},?\d{0,2}\s*[x×]/i', $descricao, $match)) {
            return trim($match[1]);
        }

        return mb_substr($descricao, 0, 50);
    }

    /**
     * Extrai medidas de largura, profundidade e altura.
     *
     * @param string $descricao
     * @return array
     */
    protected function extrairMedidas(string $descricao): array
    {
        if (preg_match('/(\d{1,3},?\d{0,2})\s*[x×]\s*(\d{1,3},?\d{0,2})\s*[x×]\s*(\d{1,3},?\d{0,2})/i', $descricao, $match)) {
            return [
                'largura' => str_replace(',', '.', $match[1]),
                'profundidade' => str_replace(',', '.', $match[2]),
                'altura' => str_replace(',', '.', $match[3]),
            ];
        }

        return ['largura' => null, 'profundidade' => null, 'altura' => null];
    }

    /**
     * Extrai atributos (cor, tecido, acabamento, observações).
     *
     * @param string $descricao
     * @param string $nome
     * @return array
     */
    protected function extrairAtributos(string $descricao, string $nome): array
    {
        $atributos = [
            'cores' => [],
            'tecidos' => [],
            'acabamentos' => [],
            'observacoes' => [],
        ];

        $partes = preg_split('/\*+/', $descricao);

        foreach ($partes as $parte) {
            $parte = trim($parte);
            if ($parte === '' || stripos($parte, $nome) !== false) {
                continue;
            }

            $parteUpper = mb_strtoupper($parte, 'UTF-8');

            if (str_contains($parteUpper, 'COR')) {
                $atributos['cores'][] = trim(str_ireplace('COR', '', $parte));
            } elseif (str_contains($parteUpper, 'TECIDO')) {
                $atributos['tecidos'][] = trim(str_ireplace('TECIDO', '', $parte));
            } elseif (str_contains($parteUpper, 'ACABAMENTO')) {
                $atributos['acabamentos'][] = trim(str_ireplace('ACABAMENTO', '', $parte));
            } else {
                $atributos['observacoes'][] = $parte;
            }
        }

        return $atributos;
    }

    /**
     * Extrai a quantidade do produto na linha.
     *
     * @param string $linha
     * @return float
     */
    protected function extrairQuantidade(string $linha): float
    {
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\b/', $linha, $match)) {
            return (float) str_replace(',', '.', $match[1]);
        }
        return 1.0;
    }

    /**
     * Extrai o preço unitário da linha.
     *
     * @param string $linha
     * @return float
     */
    protected function extrairPreco(string $linha): float
    {
        if (preg_match('/R?\$?\s?(\d{1,3}(?:[\.,]\d{3})*[\.,]\d{2})/i', $linha, $match)) {
            return (float) str_replace(['.', ','], ['', '.'], $match[1]);
        }
        return 0.0;
    }

    /**
     * Extrai a referência da linha.
     *
     * @param string $linha
     * @return string|null
     */
    protected function extrairReferencia(string $linha): ?string
    {
        if (preg_match('/REF(?:\.|:)?\s*([A-Z0-9\-]+)/i', $linha, $match)) {
            return trim($match[1]);
        }
        return null;
    }
}
