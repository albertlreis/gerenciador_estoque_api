<?php

namespace Database\Seeders;

use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacaoOutletSeeder extends Seeder
{
    public function run(): void
    {
        // Recupera o ID do admin
        $adminId = DB::table('acesso_usuarios')
            ->where('email', 'admin@teste.com')
            ->value('id');

        if (!$adminId) {
            echo "Usuário admin@teste.com não encontrado.\n";
            return;
        }

        // Lista de motivos possíveis
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

        $variacoes = ProdutoVariacao::inRandomOrder()->take(5)->get();

        foreach ($variacoes as $variacao) {
            $qtdRegistros = rand(1, 2);
            for ($i = 0; $i < $qtdRegistros; $i++) {
                $quantidade = rand(1, 5);
                ProdutoVariacaoOutlet::create([
                    'produto_variacao_id'   => $variacao->id,
                    'motivo'                => collect($motivos)->random(),
                    'quantidade'            => $quantidade,
                    'quantidade_restante'   => $quantidade,
                    'percentual_desconto'   => rand(10, 50) + rand(0, 99) / 100, // Ex: 32.75
                    'usuario_id'            => $adminId,
                    'created_at'            => Carbon::now()->subDays(rand(0, 30)),
                ]);
            }
        }
    }
}
