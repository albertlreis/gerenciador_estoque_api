<?php

namespace Database\Seeders;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DespesasRecorrentesSeeder extends Seeder
{
    public function run(): void
    {
        $usuarioId = DB::table('acesso_usuarios')->orderBy('id')->value('id');
        if (!$usuarioId) {
            $this->command?->warn('Nenhum acesso_usuario encontrado. Pulei DespesasRecorrentesSeeder.');
            return;
        }

        $fornecedores = DB::table('fornecedores')->limit(5)->pluck('id')->toArray();
        $now = Carbon::now();

        $ccAdm = CentroCusto::where('slug','administrativo')->first() ?? CentroCusto::where('ativo',1)->first();
        $ccTi  = CentroCusto::where('slug','ti')->first() ?? CentroCusto::where('ativo',1)->first();
        $ccOp  = CentroCusto::where('slug','operacao')->first() ?? CentroCusto::where('ativo',1)->first();

        $catAluguel  = CategoriaFinanceira::where('tipo','despesa')->where('slug','aluguel')->first()
            ?? CategoriaFinanceira::where('tipo','despesa')->first();
        $catInternet = CategoriaFinanceira::where('tipo','despesa')->where('slug','internet')->first()
            ?? CategoriaFinanceira::where('tipo','despesa')->first();
        $catEnergia  = CategoriaFinanceira::where('tipo','despesa')->where('slug','energia')->first()
            ?? CategoriaFinanceira::where('tipo','despesa')->first();
        $catSoftware = CategoriaFinanceira::where('tipo','despesa')->where('slug','software')->first()
            ?? CategoriaFinanceira::where('tipo','despesa')->first();
        $catSeguro   = CategoriaFinanceira::where('tipo','despesa')->where('slug','seguro')->first()
            ?? CategoriaFinanceira::where('tipo','despesa')->first();

        $despesas = [
            [
                'descricao' => 'Aluguel Loja Principal',
                'tipo' => 'FIXA', 'frequencia' => 'MENSAL', 'intervalo' => 1, 'dia_vencimento' => 5,
                'valor_bruto' => 8500.00,
                'categoria_id' => $catAluguel?->id, 'centro_custo_id' => $ccAdm?->id,
            ],
            [
                'descricao' => 'Internet Fibra Óptica',
                'tipo' => 'FIXA', 'frequencia' => 'MENSAL', 'intervalo' => 1, 'dia_vencimento' => 10,
                'valor_bruto' => 420.00,
                'categoria_id' => $catInternet?->id, 'centro_custo_id' => $ccTi?->id,
            ],
            [
                'descricao' => 'Energia Elétrica',
                'tipo' => 'VARIAVEL', 'frequencia' => 'MENSAL', 'intervalo' => 1, 'dia_vencimento' => 15,
                'valor_bruto' => null,
                'categoria_id' => $catEnergia?->id, 'centro_custo_id' => $ccOp?->id,
            ],
            [
                'descricao' => 'Sistema ERP / Financeiro',
                'tipo' => 'FIXA', 'frequencia' => 'MENSAL', 'intervalo' => 1, 'dia_vencimento' => 20,
                'valor_bruto' => 699.90,
                'categoria_id' => $catSoftware?->id, 'centro_custo_id' => $ccTi?->id,
            ],
            [
                'descricao' => 'Seguro Patrimonial',
                'tipo' => 'FIXA', 'frequencia' => 'ANUAL', 'intervalo' => 1, 'dia_vencimento' => 30, 'mes_vencimento' => 3,
                'valor_bruto' => 3200.00,
                'categoria_id' => $catSeguro?->id, 'centro_custo_id' => $ccAdm?->id,
            ],
        ];

        foreach ($despesas as $index => $d) {
            DB::table('despesas_recorrentes')->insert([
                'fornecedor_id' => $fornecedores[$index] ?? null,
                'descricao' => $d['descricao'],
                'numero_documento' => null,

                'categoria_id' => $d['categoria_id'] ?? null,
                'centro_custo_id' => $d['centro_custo_id'] ?? null,

                'valor_bruto' => $d['valor_bruto'],
                'desconto' => 0, 'juros' => 0, 'multa' => 0,

                'tipo' => $d['tipo'],
                'frequencia' => $d['frequencia'],
                'intervalo' => $d['intervalo'],
                'dia_vencimento' => $d['dia_vencimento'],
                'mes_vencimento' => $d['mes_vencimento'] ?? null,

                'data_inicio' => $now->copy()->startOfMonth()->toDateString(),
                'data_fim' => null,

                'criar_conta_pagar_auto' => true,
                'dias_antecedencia' => 0,
                'status' => 'ATIVA',

                'observacoes' => 'Seed inicial de despesas recorrentes',
                'usuario_id' => $usuarioId,

                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
