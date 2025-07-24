<?php

namespace Database\Seeders;

use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacaoOutletSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = DB::table('acesso_usuarios')
            ->where('email', 'admin@teste.com')
            ->value('id');

        if (!$adminId) {
            echo "Usuário admin@teste.com não encontrado.\n";
            return;
        }

        $motivos = [
            'tempo_estoque',
            'saiu_linha',
            'avariado',
            'devolvido',
            'exposicao',
            'embalagem_danificada',
            'baixa_rotatividade',
            'erro_cadastro',
            'excedente',
            'promocao_pontual',
        ];

        $formasPagamento = [
            ['forma_pagamento' => 'avista', 'parcelas' => null],
            ['forma_pagamento' => 'boleto', 'parcelas' => 6],
            ['forma_pagamento' => 'cartao', 'parcelas' => 12],
        ];

        $variacoes = ProdutoVariacao::inRandomOrder()->take(5)->get();

        foreach ($variacoes as $variacao) {
            $qtdRegistros = rand(1, 2);

            for ($i = 0; $i < $qtdRegistros; $i++) {
                $quantidade = rand(1, 5);
                $outlet = ProdutoVariacaoOutlet::create([
                    'produto_variacao_id' => $variacao->id,
                    'motivo' => collect($motivos)->random(),
                    'quantidade' => $quantidade,
                    'quantidade_restante' => $quantidade,
                    'usuario_id' => $adminId,
                    'created_at' => Carbon::now()->subDays(rand(0, 30)),
                ]);

                // Cria de 1 a 3 formas de pagamento aleatórias para o outlet
                collect($formasPagamento)
                    ->shuffle()
                    ->take(rand(1, 3))
                    ->each(function ($fp) use ($outlet) {
                        ProdutoVariacaoOutletPagamento::create([
                            'produto_variacao_outlet_id' => $outlet->id,
                            'forma_pagamento' => $fp['forma_pagamento'],
                            'percentual_desconto' => rand(10, 50) + rand(0, 99) / 100,
                            'max_parcelas' => $fp['parcelas'],
                        ]);
                    });
            }
        }
    }
}
