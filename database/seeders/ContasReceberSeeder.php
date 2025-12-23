<?php

namespace Database\Seeders;

use App\Enums\ContaReceberStatusEnum;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Pedido;
use App\Services\ContaReceberService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContasReceberSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ContaReceberService $service */
        $service = app(ContaReceberService::class);

        // Ajuste aqui para "muitos registros"
        $maxPedidos = (int) (env('SEED_CONTAS_RECEBER_MAX_PEDIDOS', 1500));
        $seedParcelasMin = (int) (env('SEED_CONTAS_RECEBER_PARCELAS_MIN', 1));
        $seedParcelasMax = (int) (env('SEED_CONTAS_RECEBER_PARCELAS_MAX', 3));

        $formasRecebimento = ['pix', 'boleto', 'cartao', 'transferencia', 'dinheiro'];
        $centros = ['Vendas', 'Online', 'Projetos', 'Showroom'];
        $categorias = ['Receitas', 'Venda de Produtos', 'Venda Profissional', 'Serviços'];

        // Busca pedidos reais
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

        DB::transaction(function () use ($pedidos, $service, $seedParcelasMin, $seedParcelasMax, $formasRecebimento, $centros, $categorias) {

            foreach ($pedidos as $pedido) {
                $total = (float) $pedido->valor_total;
                if ($total <= 0) continue;

                $parcelas = random_int($seedParcelasMin, $seedParcelasMax);

                // Pequena variação para simular descontos/juros/multa
                $desconto = (random_int(0, 100) <= 12) ? round($total * (random_int(1, 5) / 100), 2) : 0;
                $juros = 0;
                $multa = 0;

                $baseEmissao = $pedido->data_pedido ? Carbon::parse($pedido->data_pedido) : now()->subDays(random_int(1, 120));
                $primeiroVenc = $pedido->data_limite_entrega ? Carbon::parse($pedido->data_limite_entrega) : $baseEmissao->copy()->addDays(30);

                // Divide valor em parcelas com ajuste na última
                $valorParcela = round(($total - $desconto) / $parcelas, 2);

                for ($i = 1; $i <= $parcelas; $i++) {
                    $venc = $primeiroVenc->copy()->addDays(30 * ($i - 1));
                    $valor = ($i === $parcelas)
                        ? round(($total - $desconto) - ($valorParcela * ($parcelas - 1)), 2)
                        : $valorParcela;

                    // Alguns vencidos terão juros/multa
                    if ($venc->lt(now()->startOfDay()) && random_int(0, 100) <= 30) {
                        $juros = round($valor * (random_int(1, 3) / 100), 2);
                        $multa = round($valor * (random_int(1, 2) / 100), 2);
                    } else {
                        $juros = 0;
                        $multa = 0;
                    }

                    $valorLiquido = max(0, round($valor - ($i === 1 ? $desconto : 0) + $juros + $multa, 2));

                    $conta = ContaReceber::create([
                        'pedido_id'         => $pedido->id,
                        'descricao'         => "Recebimento Pedido #{$pedido->id} - Parcela {$i}/{$parcelas}",
                        'numero_documento'  => ($pedido->numero_externo ?: "PED-{$pedido->id}") . "-{$i}",
                        'data_emissao'      => $baseEmissao->copy()->toDateString(),
                        'data_vencimento'   => $venc->toDateString(),
                        'valor_bruto'       => $valor,
                        'desconto'          => ($i === 1 ? $desconto : 0),
                        'juros'             => $juros,
                        'multa'             => $multa,
                        'valor_liquido'     => $valorLiquido,
                        'valor_recebido'    => 0,
                        'saldo_aberto'      => $valorLiquido,
                        'status'            => ContaReceberStatusEnum::ABERTO->value,
                        'forma_recebimento' => $formasRecebimento[array_rand($formasRecebimento)],
                        'centro_custo'      => $centros[array_rand($centros)],
                        'categoria'         => $categorias[array_rand($categorias)],
                        'observacoes'       => null,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                    // --- Pagamentos: cria 0, 1 ou 2
                    $cenario = random_int(1, 100);

                    if ($cenario <= 45) {
                        // 45%: sem pagamento => ABERTO ou VENCIDO (recalcular resolve)
                    } elseif ($cenario <= 75) {
                        // 30%: parcial
                        $pago = round($valorLiquido * (random_int(20, 80) / 100), 2);

                        ContaReceberPagamento::create([
                            'conta_receber_id' => $conta->id,
                            'data_pagamento'   => $venc->copy()->subDays(random_int(0, 10))->toDateString(),
                            'valor_pago'       => $pago,
                            'forma_pagamento'  => $conta->forma_recebimento ?: 'Indefinido',
                            'comprovante'      => null,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                    } else {
                        // 25%: recebido total (às vezes com 2 pagamentos)
                        $doisPag = random_int(0, 100) <= 40;

                        if ($doisPag) {
                            $p1 = round($valorLiquido * 0.5, 2);
                            $p2 = round($valorLiquido - $p1, 2);

                            ContaReceberPagamento::create([
                                'conta_receber_id' => $conta->id,
                                'data_pagamento'   => $venc->copy()->subDays(random_int(0, 15))->toDateString(),
                                'valor_pago'       => $p1,
                                'forma_pagamento'  => $conta->forma_recebimento ?: 'Indefinido',
                                'comprovante'      => null,
                                'created_at'       => now(),
                                'updated_at'       => now(),
                            ]);

                            ContaReceberPagamento::create([
                                'conta_receber_id' => $conta->id,
                                'data_pagamento'   => $venc->copy()->subDays(random_int(0, 5))->toDateString(),
                                'valor_pago'       => $p2,
                                'forma_pagamento'  => $conta->forma_recebimento ?: 'Indefinido',
                                'comprovante'      => null,
                                'created_at'       => now(),
                                'updated_at'       => now(),
                            ]);
                        } else {
                            ContaReceberPagamento::create([
                                'conta_receber_id' => $conta->id,
                                'data_pagamento'   => $venc->copy()->subDays(random_int(0, 10))->toDateString(),
                                'valor_pago'       => $valorLiquido,
                                'forma_pagamento'  => $conta->forma_recebimento ?: 'Indefinido',
                                'comprovante'      => null,
                                'created_at'       => now(),
                                'updated_at'       => now(),
                            ]);
                        }
                    }

                    // Recalcula (status/saldo/recebido) com base nos pagamentos criados
                    $service->recalcular($conta->fresh(), true);

                    // ~3% das contas: soft delete com estorno automático (pra testar feature)
                    if (random_int(1, 100) <= 3) {
                        $service->remover($conta->fresh(), 'Seed: teste de estorno + soft delete');
                    }
                }
            }
        });
    }
}
