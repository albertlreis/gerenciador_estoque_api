<?php

namespace Database\Seeders;

use App\Enums\ContaPagarStatus;
use App\Models\ContaPagar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ContaPagarSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = [
            'Serviços Gerais',
            'Manutenção Predial',
            'Marketing e Publicidade',
            'Tecnologia',
            'Transporte e Logística',
            'Materiais de Escritório',
            'Energia Elétrica',
            'Água e Saneamento',
            'Fornecedores de Produtos',
        ];

        $centrosCusto = ['Administrativo', 'Operacional', 'Financeiro', 'Comercial'];
        $formas = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];
        $descricaoExemplos = [
            'Pagamento de Energia Elétrica',
            'Serviço de Limpeza e Conservação',
            'Compra de Materiais de Escritório',
            'Manutenção de Equipamentos',
            'Despesa de Transporte',
            'Serviço de Consultoria Contábil',
            'Hospedagem de Site e Domínio',
            'Campanha de Marketing Digital',
            'Compra de Suprimentos de TI',
            'Serviço de Jardinagem',
            'Reparo Hidráulico Emergencial',
        ];

        foreach (range(1, 40) as $i) {
            $valor = fake()->randomFloat(2, 300, 5000);
            $desconto = fake()->boolean(20) ? fake()->randomFloat(2, 0, 200) : 0;
            $juros = fake()->boolean(15) ? fake()->randomFloat(2, 0, 150) : 0;
            $multa = fake()->boolean(10) ? fake()->randomFloat(2, 0, 80) : 0;

            $emissao = Carbon::now()->subDays(rand(0, 60));
            $vencimento = (clone $emissao)->addDays(rand(10, 30));

            $status = fake()->randomElement([
                ContaPagarStatus::ABERTA,
                ContaPagarStatus::PARCIAL,
                ContaPagarStatus::PAGA,
            ]);

            ContaPagar::create([
                'fornecedor_id'   => null,
                'descricao'       => fake()->randomElement($descricaoExemplos),
                'numero_documento'=> Str::upper(fake()->bothify('NF-###??')),
                'data_emissao'    => $emissao,
                'data_vencimento' => $vencimento,
                'valor_bruto'     => $valor,
                'desconto'        => $desconto,
                'juros'           => $juros,
                'multa'           => $multa,
                'status'          => $status->value,
                'forma_pagamento' => fake()->randomElement($formas),
                'centro_custo'    => fake()->randomElement($centrosCusto),
                'categoria'       => fake()->randomElement($categorias),
                'observacoes'     => fake()->boolean(30) ? fake()->sentence() : null,
            ]);
        }
    }
}
