<?php

namespace Database\Seeders;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class LancamentosFinanceirosSeeder extends Seeder
{
    public function run(): void
    {
        $conta = ContaFinanceira::query()->where('ativo', 1)->orderBy('id')->first();
        $cc    = CentroCusto::query()->where('ativo', 1)->orderBy('id')->first();

        if (!$conta || !$cc) return;

        // Categorias por tipo (preferindo "filhas" para ficar mais realista)
        $catsReceita = CategoriaFinanceira::query()
            ->where('ativo', 1)
            ->where('tipo', 'receita')
            ->orderByRaw('categoria_pai_id is null') // filhas primeiro
            ->orderBy('ordem')
            ->get();

        $catsDespesa = CategoriaFinanceira::query()
            ->where('ativo', 1)
            ->where('tipo', 'despesa')
            ->orderByRaw('categoria_pai_id is null')
            ->orderBy('ordem')
            ->get();

        // Fallbacks (se tiver pouco cadastro)
        $catVenda   = CategoriaFinanceira::where('slug', 'vendas')->first();
        $catImposto = CategoriaFinanceira::where('slug', 'impostos')->first();

        if ($catsReceita->isEmpty() && $catVenda) $catsReceita = collect([$catVenda]);
        if ($catsDespesa->isEmpty() && $catImposto) $catsDespesa = collect([$catImposto]);

        if ($catsReceita->isEmpty() || $catsDespesa->isEmpty()) return;

        // “Templates” para ficar com cara de extrato
        $descReceitas = [
            'Venda balcão',
            'Venda e-commerce',
            'Recebimento cliente',
            'Entrada PIX',
            'Cartão crédito (recebimento)',
            'Depósito em conta',
            'Venda consignado (ajuste)',
        ];

        $descDespesas = [
            'Pagamento fornecedor',
            'Frete/transportadora',
            'Impostos e taxas',
            'Aluguel',
            'Energia elétrica',
            'Internet/telefonia',
            'Compra insumos',
            'Taxa operadora cartão',
            'Manutenção/serviço',
        ];

        // Parâmetros do extrato
        $diasJanela = 90;
        $qtd = 60;

        // Vamos gerar com maior chance de receita, mas despesas relevantes
        // (e ocasionalmente cancelados para testar status)
        $chanceReceita = 0.58; // 58% receitas / 42% despesas
        $chanceCancelado = 0.06; // 6% cancelados

        for ($i = 1; $i <= $qtd; $i++) {
            // “Efeito extrato”: datas aleatórias, mas com tendência a "dias úteis"
            $diasAtras = random_int(0, $diasJanela);
            $data = Carbon::now('America/Belem')->subDays($diasAtras)->setTime(random_int(8, 19), random_int(0, 59), 0);

            // Puxa competência do mês do movimento (padrão contábil comum)
            $competencia = $data->copy()->startOfMonth();

            $isReceita = (mt_rand() / mt_getrandmax()) < $chanceReceita;

            // Mistura “blocos” para não ficar alternância perfeita
            // (a cada 8 itens, força 2 despesas seguidas em algum ponto)
            if ($i % 8 === 0) $isReceita = false;
            if ($i % 11 === 0) $isReceita = true;

            $tipo = $isReceita ? LancamentoTipo::RECEITA : LancamentoTipo::DESPESA;

            $status = ((mt_rand() / mt_getrandmax()) < $chanceCancelado)
                ? LancamentoStatus::CANCELADO
                : LancamentoStatus::CONFIRMADO;

            // Valor: receitas maiores, despesas menores porém com alguns picos
            if ($isReceita) {
                $valor = $this->money(random_int(20000, 180000) / 100); // 200,00 a 1.800,00
                // picos
                if ($i % 13 === 0) $valor = $this->money(random_int(250000, 650000) / 100); // 2.500 a 6.500
            } else {
                $valor = $this->money(random_int(8000, 120000) / 100); // 80,00 a 1.200,00
                // despesas pesadas ocasionais
                if ($i % 9 === 0) $valor = $this->money(random_int(180000, 480000) / 100); // 1.800 a 4.800
            }

            $categoria = $isReceita
                ? $catsReceita->random()
                : $catsDespesa->random();

            $descricao = $isReceita
                ? $descReceitas[array_rand($descReceitas)]
                : $descDespesas[array_rand($descDespesas)];

            // Seed key idempotente
            $seedKey = "seed:lanc_fin:extrato:v1:item={$i}";
            $obs = $seedKey;

            // firstOrCreate pela seedKey em observacoes
            LancamentoFinanceiro::query()->firstOrCreate(
                ['observacoes' => $obs],
                [
                    'descricao'        => $descricao,
                    'tipo'             => $tipo->value,
                    'status'           => $status->value,
                    'categoria_id'     => $categoria->id,
                    'centro_custo_id'  => $cc->id,
                    'conta_id'         => $conta->id,
                    'valor'            => $valor,
                    'data_movimento'   => $data,
                    'competencia'      => $competencia,
                ]
            );
        }

        // Um “complemento” mais explícito (exemplos que você já tinha)
        $this->seedFixos($conta->id, $cc->id, $catsReceita, $catsDespesa);
    }

    private function seedFixos(int $contaId, int $ccId, $catsReceita, $catsDespesa): void
    {
        $fixos = [
            [
                'key' => 'seed:lanc_fin:fixo:venda_manual',
                'descricao' => 'Venda (lançamento manual)',
                'tipo' => LancamentoTipo::RECEITA->value,
                'status' => LancamentoStatus::CONFIRMADO->value,
                'categoria_id' => $catsReceita->first()->id,
                'valor' => 3500.00,
                'data_movimento' => now('America/Belem')->subDays(4),
                'competencia' => now('America/Belem')->subMonth()->startOfMonth(),
            ],
            [
                'key' => 'seed:lanc_fin:fixo:imposto_manual',
                'descricao' => 'Pagamento imposto (lançamento manual)',
                'tipo' => LancamentoTipo::DESPESA->value,
                'status' => LancamentoStatus::CONFIRMADO->value,
                'categoria_id' => $catsDespesa->first()->id,
                'valor' => 950.00,
                'data_movimento' => now('America/Belem')->subDays(7),
                'competencia' => now('America/Belem')->subMonth()->startOfMonth(),
            ],
        ];

        foreach ($fixos as $f) {
            LancamentoFinanceiro::query()->firstOrCreate(
                ['observacoes' => $f['key']],
                [
                    'descricao'        => $f['descricao'],
                    'tipo'             => $f['tipo'],
                    'status'           => $f['status'],
                    'categoria_id'     => $f['categoria_id'],
                    'centro_custo_id'  => $ccId,
                    'conta_id'         => $contaId,
                    'valor'            => $f['valor'],
                    'data_movimento'   => $f['data_movimento'],
                    'competencia'      => $f['competencia'],
                ]
            );
        }
    }

    private function money(float $v): float
    {
        return round($v, 2);
    }
}
