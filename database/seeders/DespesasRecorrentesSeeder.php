<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DespesasRecorrentesSeeder extends Seeder
{
    public function run(): void
    {
        // 🔹 Busca usuário admin (fallback seguro)
        $usuarioId = DB::table('acesso_usuarios')
            ->orderBy('id')
            ->value('id');

        // 🔹 Busca alguns fornecedores existentes
        $fornecedores = DB::table('fornecedores')
            ->limit(5)
            ->pluck('id')
            ->toArray();

        $now = Carbon::now();

        $despesas = [
            [
                'descricao' => 'Aluguel Loja Principal',
                'tipo' => 'FIXA',
                'frequencia' => 'MENSAL',
                'intervalo' => 1,
                'dia_vencimento' => 5,
                'valor_bruto' => 8500.00,
                'categoria' => 'Aluguel',
                'centro_custo' => 'Administrativo',
            ],
            [
                'descricao' => 'Internet Fibra Óptica',
                'tipo' => 'FIXA',
                'frequencia' => 'MENSAL',
                'intervalo' => 1,
                'dia_vencimento' => 10,
                'valor_bruto' => 420.00,
                'categoria' => 'Internet',
                'centro_custo' => 'TI',
            ],
            [
                'descricao' => 'Energia Elétrica',
                'tipo' => 'VARIAVEL',
                'frequencia' => 'MENSAL',
                'intervalo' => 1,
                'dia_vencimento' => 15,
                'valor_bruto' => null, // variável → informado na execução
                'categoria' => 'Energia',
                'centro_custo' => 'Operacional',
            ],
            [
                'descricao' => 'Sistema ERP / Financeiro',
                'tipo' => 'FIXA',
                'frequencia' => 'MENSAL',
                'intervalo' => 1,
                'dia_vencimento' => 20,
                'valor_bruto' => 699.90,
                'categoria' => 'Software',
                'centro_custo' => 'TI',
            ],
            [
                'descricao' => 'Seguro Patrimonial',
                'tipo' => 'FIXA',
                'frequencia' => 'ANUAL',
                'intervalo' => 1,
                'dia_vencimento' => 30,
                'mes_vencimento' => 3, // Março
                'valor_bruto' => 3200.00,
                'categoria' => 'Seguro',
                'centro_custo' => 'Administrativo',
            ],
        ];

        foreach ($despesas as $index => $d) {
            DB::table('despesas_recorrentes')->insert([
                'fornecedor_id' => $fornecedores[$index] ?? null,
                'descricao' => $d['descricao'],
                'numero_documento' => null,

                'centro_custo' => $d['centro_custo'],
                'categoria' => $d['categoria'],

                'valor_bruto' => $d['valor_bruto'],
                'desconto' => 0,
                'juros' => 0,
                'multa' => 0,

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
