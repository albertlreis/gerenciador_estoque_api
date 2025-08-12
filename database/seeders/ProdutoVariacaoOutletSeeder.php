<?php

namespace Database\Seeders;

use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

        // Catálogos por slug -> id
        $motivoSlugs = [
            'tempo_estoque','saiu_linha','avariado','devolvido','exposicao',
            'embalagem_danificada','baixa_rotatividade','erro_cadastro','excedente','promocao_pontual',
        ];
        $motivos = OutletMotivo::whereIn('slug', $motivoSlugs)->pluck('id', 'slug');

        $formaSlugs = ['avista','boleto','cartao'];
        $formas = OutletFormaPagamento::whereIn('slug', $formaSlugs)
            ->get()
            ->keyBy('slug');

        // 5 variações aleatórias
        $variacoes = ProdutoVariacao::with(['estoque','outlets'])->inRandomOrder()->take(5)->get();

        foreach ($variacoes as $variacao) {
            $qtdOutletsParaCriar = rand(1, 2);

            for ($i = 0; $i < $qtdOutletsParaCriar; $i++) {
                $estoqueTotal = (int)($variacao->estoque->quantidade ?? 0);
                $jaRegistrado = (int)$variacao->outlets->sum('quantidade');
                $disponivel   = max(0, $estoqueTotal - $jaRegistrado);

                if ($disponivel <= 0) {
                    // sem saldo para novo outlet
                    continue 2;
                }

                $quantidade = min(rand(1, 5), $disponivel);

                $motivoId = $motivos->values()->random();
                $outlet = ProdutoVariacaoOutlet::create([
                    'produto_variacao_id' => $variacao->id,
                    'motivo_id' => $motivoId,
                    'quantidade' => $quantidade,
                    'quantidade_restante' => $quantidade,
                    'usuario_id' => $adminId,
                    'created_at' => Carbon::now()->subDays(rand(0, 30)),
                    'updated_at' => Carbon::now(),
                ]);

                // Formas de 1 a 3
                $formasEscolhidas = $formas->shuffle()->take(rand(1, 3));
                foreach ($formasEscolhidas as $slug => $forma) {
                    ProdutoVariacaoOutletPagamento::create([
                        'produto_variacao_outlet_id' => $outlet->id,
                        'forma_pagamento_id' => $forma->id,
                        'percentual_desconto' => rand(10, 50) + rand(0, 99) / 100,
                        'max_parcelas' => $forma->max_parcelas_default ?: null,
                    ]);
                }

                // Atualiza a relação em memória para refletir a nova soma numa próxima iteração
                $variacao->outlets->push($outlet);
            }
        }
    }
}
