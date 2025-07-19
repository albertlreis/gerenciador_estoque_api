<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

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
        $linhas = preg_split('/\r\n|\n|\r/', $texto);
        $itens = [];
        $bloco = '';

        $padraoQuantidade = '/^\d{1,2}\.\d{4}/';
        $padraoValor = '/\d{1,3}(?:\.\d{3})*,\d{2}$/';

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;

            if (preg_match($padraoQuantidade, $linha)) {
                if (!empty($bloco)) {
                    $item = $this->processarBlocoProduto($bloco);
                    if ($item) $itens[] = $item;
                    $bloco = '';
                }
            }

            $bloco .= ' ' . $linha;

            if (preg_match($padraoValor, $linha)) {
                $item = $this->processarBlocoProduto($bloco);
                if ($item) $itens[] = $item;
                $bloco = '';
            }
        }

        if (!empty($bloco)) {
            $item = $this->processarBlocoProduto($bloco);
            if ($item) $itens[] = $item;
        }

        return $itens;
    }

    /**
     * Processa um bloco de texto referente a um item do pedido.
     */
    protected function processarBlocoProduto(string $bloco): ?array
    {
        $bloco = trim(preg_replace('/\s+/', ' ', $bloco));

        if (!preg_match('/(?<valor>\d{1,3}(?:\.\d{3})*,\d{2})$/', $bloco, $valorMatch)) {
            return null;
        }

        $valor = (float) str_replace(['.', ','], ['', '.'], $valorMatch['valor']);
        $blocoSemValor = trim(str_replace($valorMatch[0], '', $bloco));

        if (!preg_match('/^(?<quantidade>\d{1,2}\.\d{4})\s*(?:(?<tipo>PEDIDO|PRONTA\s+ENTREGA))?\s*(?<ref>[A-Z0-9\-]+)?\s+(?<descricao>.+)$/i', $blocoSemValor, $match)) {
            return null;
        }

        $quantidade = (float) str_replace(',', '.', $match['quantidade']);
        $tipo = strtoupper(trim($match['tipo'] ?? ''));
        $ref = strtoupper(trim($match['ref'] ?? ''));

        $descricaoOriginal = trim($match['descricao']);
        $descricaoNormalizada = $this->normalizarDescricao($descricaoOriginal);

        if ($quantidade <= 0 || $valor <= 0 || mb_strlen($descricaoNormalizada) < 10) {
            return null;
        }

        $produto = $this->extrairProduto($descricaoNormalizada);

        return [
            'descricao' => $descricaoNormalizada,
            'quantidade' => $quantidade,
            'valor' => $valor,
            'ref' => $ref,
            'tipo' => $tipo,
            'nome' => $produto['nome'],
            'fixos' => $produto['fixos'],
            'atributos' => $produto['atributos'],
        ];
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
        $descricao = $this->normalizarDescricao($descricao);

        return [
            'nome' => $this->extrairNomeProduto($descricao),
            'fixos' => $this->extrairMedidas($descricao),
            'atributos' => $this->extrairAtributos($descricao),
        ];
    }

    /**
     * Extrai nome base do produto, removendo medidas, atributos e espaços invisíveis.
     *
     * @param string $descricao
     * @return string
     */
    protected function extrairNomeProduto(string $descricao): string
    {
        $descricao = $this->normalizarDescricao($descricao);

        // Se contiver '*', divide e pega antes
        if (str_contains($descricao, '*')) {
            [$possivelNome] = explode('*', $descricao, 2);
        } else {
            // Interrompe antes de atributos ou medidas (mesmo coladas)
            preg_match(
                '/^(.*?)(?=\s*(COR\b|TECIDO\b|MED\b|PESP\b|MÁRMORE\b|PRONTA\b|PEDIDO\b)|\d{2,3}\s*[xXØ]\s*\d{1,3}(?:\s*[xXØ]\s*\d{1,3})?(?:\s*CM)?\b)/iu',
                $descricao,
                $match
            );

            $possivelNome = $match[1] ?? $descricao;
        }

        // Remove medidas coladas ao final como "53X5X81CM", "260X122X78"
        $possivelNome = preg_replace('/\d{2,3}\s*[xXØ]\s*\d{1,3}(?:\s*[xXØ]\s*\d{1,3})?(?:\s*CM)?\b/iu', '', $possivelNome);

        // Limpeza final
        $nome = trim(preg_replace('/[^\p{L}\p{N} \/]/u', '', $possivelNome));
        return preg_replace('/\s+/', ' ', $nome);
    }


    /**
     * Extrai medidas de largura, profundidade e altura da descrição.
     *
     * @param string $descricao
     * @return array{largura: int|null, profundidade: int|null, altura: int|null}
     */
    protected function extrairMedidas(string $descricao): array
    {
        $descricao = mb_strtoupper($this->normalizarDescricao($descricao));
        $descricao = str_replace(['Ø', 'X'], 'x', $descricao);

        // Captura medidas tipo 53x40x75 ou 53 x 40 x 75 (com ou sem espaços)
        if (preg_match('/(\d{2,3})\s*x\s*(\d{2,3})\s*x\s*(\d{2,3})/', $descricao, $matches)) {
            return [
                'largura' => (int) $matches[1],
                'profundidade' => (int) $matches[2],
                'altura' => (int) $matches[3],
            ];
        }

        // Captura medidas parciais tipo 53x40 (sem altura)
        if (preg_match('/(\d{2,3})\s*x\s*(\d{2,3})/', $descricao, $matches)) {
            return [
                'largura' => (int) $matches[1],
                'profundidade' => (int) $matches[2],
                'altura' => null,
            ];
        }

        // Captura medidas tipo 53x5x81 ou coladas como 53x5x81 (sem espaços)
        if (preg_match('/(\d{2,3})x(\d{1,3})(?:x(\d{1,3}))?/i', $descricao, $matches)) {
            return [
                'largura' => (int) $matches[1],
                'profundidade' => (int) $matches[2],
                'altura' => isset($matches[3]) ? (int) $matches[3] : null,
            ];
        }

        // Captura padrão colado como 53X5X81CM no nome (sem separadores visuais)
        if (preg_match('/(\d{2,3})\s*[xX]\s*(\d{1,3})\s*[xX]\s*(\d{1,3})/u', $descricao, $matchAlt)) {
            return [
                'largura' => (int) $matchAlt[1],
                'profundidade' => (int) $matchAlt[2],
                'altura' => (int) $matchAlt[3],
            ];
        }

        return [
            'largura' => null,
            'profundidade' => null,
            'altura' => null,
        ];
    }

    /**
     * Extrai atributos do produto agrupados por tipo: cores, tecidos e acabamento.
     *
     * @param string $descricao
     * @return array<string, array<string, string>>
     */
    protected function extrairAtributos(string $descricao): array
    {
        $descricao = $this->normalizarDescricao($descricao);
        $atributos = [
            'cores' => [],
            'tecidos' => [],
            'acabamento' => [],
        ];

        // Substituições manuais para padronização
        $substituicoes = [
            'CALACA TA POLIDO' => 'CALACATA POLIDO',
            'CALACA TA'         => 'CALACATA',
        ];
        $descricao = str_ireplace(array_keys($substituicoes), array_values($substituicoes), $descricao);

        // Ordem importa: padrões mais específicos primeiro
        $mapaAtributos = [
            'COR DO FERRO' => ['grupo' => 'cores', 'chave' => 'cor_do_ferro'],
            'COR INOX'     => ['grupo' => 'cores', 'chave' => 'cor_inox'],
            'COR'          => ['grupo' => 'cores', 'chave' => 'cor'],
            'TECIDO UNICO' => ['grupo' => 'tecidos', 'chave' => 'tecido'],
            'TECIDO'       => ['grupo' => 'tecidos', 'chave' => 'tecido'],
            'TEC'          => ['grupo' => 'tecidos', 'chave' => 'tec'],
            'PESP'         => ['grupo' => 'tecidos', 'chave' => 'pesp'],
            'MÁRMORE'      => ['grupo' => 'acabamentos', 'chave' => 'marmore'],
            'MARMORE'      => ['grupo' => 'acabamentos', 'chave' => 'marmore'],
            'ACABAMENTO'   => ['grupo' => 'acabamentos', 'chave' => 'acabamento'],
        ];

        foreach ($mapaAtributos as $padrao => $dados) {
            $regex = '/\b' . preg_quote($padrao, '/') . '\b[:\s]*(.+?)(?=\s+\b[A-Z]{2,}\b[:\s]| MED:|\*|$)/iu';

            if (preg_match($regex, $descricao, $match)) {
                $valor = trim($match[1]);

                if (!empty($valor)) {
                    $grupo = $dados['grupo'];
                    $chave = $dados['chave'];

                    if (!isset($atributos[$grupo][$chave])) {
                        $atributos[$grupo][$chave] = $valor;
                    }
                }
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

    /**
     * Normaliza a descrição removendo símbolos especiais, espaços invisíveis e excesso de espaços.
     *
     * @param string $descricao
     * @return string
     */
    protected function normalizarDescricao(string $descricao): string
    {
        $descricao = str_replace(['Ø', "\xC2\xA0"], ' ', $descricao);
        $descricao = preg_replace('/[\x{2000}-\x{200B}]/u', ' ', $descricao);
        $descricao = preg_replace('/[^\p{L}\p{N}\*\:\(\)\/\-\.\, ]+/u', '', $descricao);
        return preg_replace('/\s+/', ' ', trim($descricao));
    }
}
