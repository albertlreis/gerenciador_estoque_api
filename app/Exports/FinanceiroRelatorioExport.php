<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinanceiroRelatorioExport implements FromArray, WithTitle
{
    public function __construct(private readonly array $dados) {}

    public function title(): string
    {
        return 'Relatorio';
    }

    public function array(): array
    {
        $periodo = $this->dados['periodo'] ?? [];
        $rows = [
            [$this->dados['titulo'] ?? 'Relatorio financeiro'],
            ['Periodo', ($periodo['inicio_label'] ?? '-') . ' a ' . ($periodo['fim_label'] ?? '-')],
            ['Gerado em', $this->dados['gerado_em'] ?? now()->format('Y-m-d H:i:s')],
            [],
            ['Resumo'],
        ];

        foreach (($this->dados['kpis'] ?? []) as $label => $valor) {
            $rows[] = [$this->label($label), $valor];
        }

        $rows[] = [];

        $colunas = $this->dados['colunas'] ?? [];
        $linhas = $this->dados['linhas'] ?? [];

        if ($colunas) {
            $rows[] = array_map(fn (array $coluna) => $coluna['header'] ?? $coluna['field'], $colunas);

            foreach ($linhas as $linha) {
                $rows[] = array_map(
                    fn (array $coluna) => $linha[$coluna['field']] ?? null,
                    $colunas
                );
            }
        }

        foreach (($this->dados['grupos'] ?? []) as $grupo => $items) {
            if (!is_array($items) || empty($items)) {
                continue;
            }

            $rows[] = [];
            $rows[] = [$this->label((string) $grupo)];
            $headers = array_keys($items[0]);
            $rows[] = array_map(fn (string $header) => $this->label($header), $headers);

            foreach ($items as $item) {
                $rows[] = array_map(fn (string $header) => $item[$header] ?? null, $headers);
            }
        }

        return $rows;
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
