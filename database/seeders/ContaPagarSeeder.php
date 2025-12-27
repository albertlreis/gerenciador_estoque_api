<?php

namespace Database\Seeders;

use App\Enums\ContaStatus;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaPagar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ContaPagarSeeder extends Seeder
{
    public function run(): void
    {
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
        ];

        $categorias = CategoriaFinanceira::query()
            ->where('tipo', 'despesa')
            ->inRandomOrder()
            ->limit(20)
            ->get();

        $centros = CentroCusto::query()
            ->where('ativo', 1)
            ->inRandomOrder()
            ->limit(20)
            ->get();

        foreach (range(1, 40) as $i) {
            $valor = fake()->randomFloat(2, 300, 5000);
            $desconto = fake()->boolean(20) ? fake()->randomFloat(2, 0, 200) : 0;
            $juros = fake()->boolean(15) ? fake()->randomFloat(2, 0, 150) : 0;
            $multa = fake()->boolean(10) ? fake()->randomFloat(2, 0, 80) : 0;

            $emissao = Carbon::now()->subDays(rand(0, 60));
            $vencimento = (clone $emissao)->addDays(rand(10, 30));

            $cat = $categorias->random();
            $cc  = $centros->random();

            ContaPagar::create([
                'fornecedor_id'     => null,
                'descricao'         => fake()->randomElement($descricaoExemplos),
                'numero_documento'  => Str::upper(fake()->bothify('NF-###??')),
                'data_emissao'      => $emissao,
                'data_vencimento'   => $vencimento,
                'valor_bruto'       => $valor,
                'desconto'          => $desconto,
                'juros'             => $juros,
                'multa'             => $multa,

                'status'            => ContaStatus::ABERTA->value,

                'categoria_id'      => $cat->id,
                'centro_custo_id'   => $cc->id,

                'observacoes'       => fake()->boolean(30) ? fake()->sentence() : null,
            ]);
        }
    }
}
