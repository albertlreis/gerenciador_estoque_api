<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinanceiroExtratoExport implements FromArray, WithTitle
{
    public function __construct(private readonly array $dados) {}

    public function title(): string
    {
        return 'Extrato';
    }

    public function array(): array
    {
        $conta = $this->dados['conta'];
        $contaDados = $this->dados['conta_dados'] ?? [];
        $periodo = $this->dados['periodo'];
        $resumo = $this->dados['resumo'];

        $rows = [
            ['Relatorio de extrato'],
            ['Conta', $conta->nome],
            ['Titular', $contaDados['titular_nome'] ?? '-'],
            ['Documento titular', $contaDados['titular_documento'] ?? '-'],
            ['Banco / agencia / conta', $contaDados['identificacao_bancaria'] ?? '-'],
            ['Moeda', $contaDados['moeda'] ?? '-'],
            ['Periodo', "{$periodo['inicio']} a {$periodo['fim']}"],
            [],
            ['Resumo'],
            ['Saldo inicial', $resumo['saldo_inicial']],
            ['Receitas realizadas', $resumo['receitas_realizadas']],
            ['Despesas realizadas', $resumo['despesas_realizadas']],
            ['Total do periodo', $resumo['total_periodo']],
            ['Cancelados', $resumo['perdidos']],
            ['Saldo final do periodo', $resumo['saldo_realizado']],
            [],
            ['Data', 'Descricao', 'Cliente/Fornecedor', 'Situacao', 'Categoria', 'Valor', 'Saldo'],
        ];

        foreach ($this->dados['linhas'] as $linha) {
            $rows[] = [
                $linha['data'],
                $linha['descricao'],
                $linha['cliente_fornecedor'],
                $linha['situacao'],
                $linha['categoria'],
                $linha['valor'],
                $linha['saldo'],
            ];
        }

        return $rows;
    }
}
