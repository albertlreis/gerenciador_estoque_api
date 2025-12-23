<?php

namespace Database\Seeders;

use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use Illuminate\Database\Seeder;

class LancamentosFinanceirosSeeder extends Seeder
{
    public function run(): void
    {
        $vendas     = CategoriaFinanceira::where('slug', 'vendas')->first();
        $impostos   = CategoriaFinanceira::where('slug', 'impostos')->first();
        $fornecedor = CategoriaFinanceira::where('slug', 'fornecedores')->first();

        $contaBanco = ContaFinanceira::where('slug', 'banco-principal')->first();
        $caixa      = ContaFinanceira::where('slug', 'caixa-loja')->first();

        // Receita paga
        LancamentoFinanceiro::create([
            'descricao' => 'Venda Pedido #1023',
            'tipo' => 'receita',
            'status' => 'pago',
            'categoria_id' => $vendas?->id,
            'conta_id' => $contaBanco?->id,
            'valor' => 3500.00,
            'data_vencimento' => now()->subDays(5),
            'data_pagamento' => now()->subDays(4),
        ]);

        // Receita pendente
        LancamentoFinanceiro::create([
            'descricao' => 'Venda Pedido #1024',
            'tipo' => 'receita',
            'status' => 'pendente',
            'categoria_id' => $vendas?->id,
            'conta_id' => $caixa?->id,
            'valor' => 1800.00,
            'data_vencimento' => now()->addDays(3),
        ]);

        // Despesa atrasada
        LancamentoFinanceiro::create([
            'descricao' => 'Fornecedor Tecidos LTDA',
            'tipo' => 'despesa',
            'status' => 'pendente',
            'categoria_id' => $fornecedor?->id,
            'conta_id' => $contaBanco?->id,
            'valor' => 2200.00,
            'data_vencimento' => now()->subDays(10),
        ]);

        // Despesa paga
        LancamentoFinanceiro::create([
            'descricao' => 'Imposto DAS Simples Nacional',
            'tipo' => 'despesa',
            'status' => 'pago',
            'categoria_id' => $impostos?->id,
            'conta_id' => $contaBanco?->id,
            'valor' => 950.00,
            'data_vencimento' => now()->subDays(7),
            'data_pagamento' => now()->subDays(7),
        ]);
    }
}
