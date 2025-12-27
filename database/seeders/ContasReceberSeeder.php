<?php

namespace Database\Seeders;

use App\Enums\ContaStatus;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\ContaReceber;
use App\Models\Pedido;
use App\Services\ContaReceberCommandService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContasReceberSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ContaReceberCommandService $cmd */
        $cmd = app(ContaReceberCommandService::class);

        $contaFinanceiraId = ContaFinanceira::query()->orderBy('id')->value('id');
        if (!$contaFinanceiraId) {
            $this->command?->warn('Nenhuma conta_financeira encontrada. Pulei contas a receber.');
            return;
        }

        $catReceita = CategoriaFinanceira::query()->where('tipo', 'receita')->inRandomOrder()->first();
        $cc = CentroCusto::query()->where('ativo', 1)->inRandomOrder()->first();

        if (!$catReceita || !$cc) {
            $this->command?->warn('Sem categoria de receita ou centro de custo. Rode seeds/migrations do catálogo antes.');
            return;
        }

        $maxPedidos = (int) (env('SEED_CONTAS_RECEBER_MAX_PEDIDOS', 1500));
        $parcelasMin = (int) (env('SEED_CONTAS_RECEBER_PARCELAS_MIN', 1));
        $parcelasMax = (int) (env('SEED_CONTAS_RECEBER_PARCELAS_MAX', 3));

        $formas = ['PIX','BOLETO','CARTAO','TED','DINHEIRO'];

        $pedidos = Pedido::query()
            ->select(['id', 'id_cliente', 'data_pedido', 'valor_total', 'data_limite_entrega', 'numero_externo'])
            ->whereNotNull('valor_total')
            ->orderByDesc('id')
            ->limit($maxPedidos)
            ->get();

        if ($pedidos->isEmpty()) {
            $this->command?->warn('Nenhum pedido encontrado para gerar contas a receber.');
            return;
        }

        DB::transaction(function () use (
            $pedidos, $cmd, $parcelasMin, $parcelasMax, $formas, $catReceita, $cc, $contaFinanceiraId
        ) {
            foreach ($pedidos as $pedido) {
                $total = (float) $pedido->valor_total;
                if ($total <= 0) continue;

                $parcelas = random_int($parcelasMin, $parcelasMax);

                $descontoTotal = (random_int(0, 100) <= 12)
                    ? round($total * (random_int(1, 5) / 100), 2)
                    : 0;

                $baseEmissao = $pedido->data_pedido ? Carbon::parse($pedido->data_pedido) : now()->subDays(random_int(1, 120));
                $primeiroVenc = $pedido->data_limite_entrega ? Carbon::parse($pedido->data_limite_entrega) : $baseEmissao->copy()->addDays(30);

                $valorParcela = round(($total - $descontoTotal) / $parcelas, 2);

                for ($i = 1; $i <= $parcelas; $i++) {
                    $venc = $primeiroVenc->copy()->addDays(30 * ($i - 1));
                    $valor = ($i === $parcelas)
                        ? round(($total - $descontoTotal) - ($valorParcela * ($parcelas - 1)), 2)
                        : $valorParcela;

                    $desconto = ($i === 1 ? $descontoTotal : 0);
                    $juros = 0;
                    $multa = 0;

                    if ($venc->lt(now()->startOfDay()) && random_int(0, 100) <= 30) {
                        $juros = round($valor * (random_int(1, 3) / 100), 2);
                        $multa = round($valor * (random_int(1, 2) / 100), 2);
                    }

                    $valorLiquido = max(0, round($valor - $desconto + $juros + $multa, 2));

                    /** @var ContaReceber $conta */
                    $conta = $cmd->criar([
                        'pedido_id'        => $pedido->id,
                        'descricao'        => "Recebimento Pedido #{$pedido->id} - Parcela {$i}/{$parcelas}",
                        'numero_documento' => ($pedido->numero_externo ?: "PED-{$pedido->id}") . "-{$i}",
                        'data_emissao'     => $baseEmissao->copy()->toDateString(),
                        'data_vencimento'  => $venc->toDateString(),
                        'valor_bruto'      => $valor,
                        'desconto'         => $desconto,
                        'juros'            => $juros,
                        'multa'            => $multa,
                        'valor_liquido'    => $valorLiquido,
                        'valor_recebido'   => 0,
                        'saldo_aberto'     => $valorLiquido,
                        'status'           => ContaStatus::ABERTA->value,
                        'forma_recebimento'=> $formas[array_rand($formas)],

                        'categoria_id'     => $catReceita->id,
                        'centro_custo_id'  => $cc->id,
                    ]);

                    // pagamentos: 0, parcial, total
                    $cenario = random_int(1, 100);

                    if ($cenario > 45 && $cenario <= 75) {
                        $pago = round($valorLiquido * (random_int(20, 80) / 100), 2);

                        $cmd->registrarPagamento($conta->fresh(), [
                            'data_pagamento' => $venc->copy()->subDays(random_int(0, 10))->toDateString(),
                            'valor' => $pago,
                            'forma_pagamento' => $formas[array_rand($formas)],
                            'conta_financeira_id' => $contaFinanceiraId,
                        ]);
                    } elseif ($cenario > 75) {
                        $dois = random_int(0, 100) <= 40;

                        if ($dois) {
                            $p1 = round($valorLiquido * 0.5, 2);
                            $p2 = round($valorLiquido - $p1, 2);

                            $cmd->registrarPagamento($conta->fresh(), [
                                'data_pagamento' => $venc->copy()->subDays(random_int(0, 15))->toDateString(),
                                'valor' => $p1,
                                'forma_pagamento' => $formas[array_rand($formas)],
                                'conta_financeira_id' => $contaFinanceiraId,
                            ]);

                            $cmd->registrarPagamento($conta->fresh(), [
                                'data_pagamento' => $venc->copy()->subDays(random_int(0, 5))->toDateString(),
                                'valor' => $p2,
                                'forma_pagamento' => $formas[array_rand($formas)],
                                'conta_financeira_id' => $contaFinanceiraId,
                            ]);
                        } else {
                            $cmd->registrarPagamento($conta->fresh(), [
                                'data_pagamento' => $venc->copy()->subDays(random_int(0, 10))->toDateString(),
                                'valor' => $valorLiquido,
                                'forma_pagamento' => $formas[array_rand($formas)],
                                'conta_financeira_id' => $contaFinanceiraId,
                            ]);
                        }
                    }
                }
            }
        });
    }
}
