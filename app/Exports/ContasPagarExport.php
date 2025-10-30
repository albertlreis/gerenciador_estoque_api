<?php

namespace App\Exports;

use App\DTOs\FiltroContaPagarDTO;
use App\Models\ContaPagar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContasPagarExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly array $params = []) {}

    public function collection(): Collection
    {
        $f = new FiltroContaPagarDTO(
            busca: $this->params['busca'] ?? null,
            fornecedor_id: $this->params['fornecedor_id'] ?? null,
            status: $this->params['status'] ?? null,
            forma_pagamento: $this->params['forma_pagamento'] ?? null,
            centro_custo: $this->params['centro_custo'] ?? null,
            categoria: $this->params['categoria'] ?? null,
            data_ini: $this->params['data_ini'] ?? null,
            data_fim: $this->params['data_fim'] ?? null,
            vencidas: $this->params['vencidas'] ?? null,
        );

        $q = ContaPagar::query();
        if ($f->busca) $q->where(fn($w)=>$w->where('descricao','like',"%{$f->busca}%")->orWhere('numero_documento','like',"%{$f->busca}%"));
        if ($f->fornecedor_id)     $q->where('fornecedor_id', $f->fornecedor_id);
        if ($f->status)            $q->where('status', $f->status);
        if ($f->forma_pagamento)   $q->where('forma_pagamento', $f->forma_pagamento);
        if ($f->centro_custo)      $q->where('centro_custo', $f->centro_custo);
        if ($f->categoria)         $q->where('categoria', $f->categoria);
        if ($f->data_ini)          $q->whereDate('data_vencimento','>=',$f->data_ini);
        if ($f->data_fim)          $q->whereDate('data_vencimento','<=',$f->data_fim);

        return $q->orderBy('data_vencimento')->get();
    }

    public function headings(): array
    {
        return ['#','Descrição','Nº Doc','Emissão','Vencimento','Bruto','Desconto','Juros','Multa','Líquido','Status','Forma'];
    }

    public function map($c): array
    {
        $liq = $c->valor_bruto - $c->desconto + $c->juros + $c->multa;
        return [
            $c->id,
            $c->descricao,
            $c->numero_documento,
            optional($c->data_emissao)->format('Y-m-d'),
            optional($c->data_vencimento)->format('Y-m-d'),
            (float) $c->valor_bruto,
            (float) $c->desconto,
            (float) $c->juros,
            (float) $c->multa,
            (float) $liq,
            $c->status,
            $c->forma_pagamento,
        ];
    }
}
