<?php

namespace App\Exports\Relatorios;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Export do Relatório de Estoque Atual (SEM imagens).
 *
 * Características:
 * - Inclui coluna "Categoria".
 * - NÃO inclui imagens (nem URL de imagem), mesmo quando somente_outlet = true.
 * - Linhas “achatadas” por depósito para facilitar leitura na planilha.
 */
class EstoqueAtualExport implements FromArray, WithHeadings, WithMapping, WithColumnFormatting
{
    /**
     * @var array<string, array{
     *   estoque_total:int|float|numeric-string|null,
     *   valor_total:int|float|numeric-string|null,
     *   estoque_por_deposito: array<int, array{id:int, nome:string, quantidade:int|float|numeric-string|null, valor:int|float|numeric-string|null}>,
     *   variacoes: array<int, mixed>,
     *   categoria?: string|null
     * }>
     */
    protected array $dados;

    /**
     * @param array<string, array> $dados Estrutura produzida pelo service.
     */
    public function __construct(array $dados)
    {
        $this->dados = $dados;
    }

    /**
     * Cabeçalhos das colunas.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        // A:Produto  B:Categoria  C:Estoque Total  D:Valor Total  E:Depósito  F:Qtd Depósito  G:Valor Depósito
        return ['Produto', 'Categoria', 'Estoque Total', 'Valor Total (R$)', 'Depósito', 'Qtd Depósito', 'Valor Depósito (R$)'];
    }

    /**
     * "Achata" os dados por depósito (uma linha por depósito do produto).
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    public function array(): array
    {
        $linhas = [];

        foreach ($this->dados as $produto => $info) {
            $deps = $info['estoque_por_deposito'] ?? [];

            // Linha placeholder quando não há depósitos (mantém totais do produto)
            if (empty($deps)) {
                $linhas[] = [
                    $produto,
                    $info['categoria'] ?? null,
                    (int) ($info['estoque_total'] ?? 0),
                    (float) ($info['valor_total'] ?? 0),
                    '-', // Depósito
                    0,   // Qtd Depósito
                    0.0, // Valor Depósito
                ];
                continue;
            }

            foreach ($deps as $dep) {
                $linhas[] = [
                    $produto,
                    $info['categoria'] ?? null,
                    (int) ($info['estoque_total'] ?? 0),
                    (float) ($info['valor_total'] ?? 0),
                    $dep['nome'] ?? '-',
                    (int) ($dep['quantidade'] ?? 0),
                    (float) ($dep['valor'] ?? 0),
                ];
            }
        }

        return $linhas;
    }

    /**
     * Mapeamento linha a linha (aqui apenas repassamos a linha gerada por array()).
     *
     * @param array<int, mixed> $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return $row;
    }

    /**
     * Formatação de colunas numéricas.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        // A B C D E F G
        return [
            'D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // Valor Total (R$)
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // Valor Depósito (R$)
        ];
    }
}
