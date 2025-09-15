<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Seeder de Pedidos de Fábrica com integridade de status:
 * - Gera pedidos com itens e quantidade_entregue coerente.
 * - Se final = 'parcial', garante pelo menos um item parcialmente entregue.
 * - Se final = 'entregue', todos itens entregues.
 * - Histórico segue a ordem: pendente -> enviado -> (parcial) -> entregue, conforme aplicável.
 */
class PedidosFabricaSeeder extends Seeder
{
    public function run(): void
    {
        $variacoes   = DB::table('produto_variacoes')->pluck('id')->toArray();
        $pedidosVenda= DB::table('pedidos')->pluck('id')->toArray();
        $depositos   = DB::table('depositos')->pluck('id')->toArray();

        if (empty($variacoes) || empty($depositos)) {
            $this->command?->warn('Variacoes ou Depositos não encontrados. Seeder abortada.');
            return;
        }

        $observacoesPool = [
            'Reposição de giro rápido.',
            'Cliente pediu para entregar mês que vem.',
            'Pedido especial com acabamento personalizado.',
            'Encomenda urgente para showroom.',
            'Reforço de estoque para Black Friday.',
            null
        ];

        // Probabilidades de status final
        $finais = ['pendente','enviado','parcial','entregue','cancelado'];
        $pesos  = [25, 20, 25, 20, 10]; // ajuste como preferir

        for ($i = 0; $i < 15; $i++) {
            $statusFinal = $this->weightedPick($finais, $pesos);

            $dataPrevisao = fake()->optional()->dateTimeBetween('+5 days', '+40 days');
            $dataPrevisao = $dataPrevisao ? Carbon::parse($dataPrevisao)->format('Y-m-d') : null;

            // timestamps coerentes
            $t0 = Carbon::now()->subDays(rand(2, 30))->startOfDay()->addMinutes(rand(0, 300));
            $t1 = (clone $t0)->addMinutes(5);
            $t2 = (clone $t0)->addMinutes(10);
            $t3 = (clone $t0)->addMinutes(15);

            // cria pedido (status temporário; atualizaremos ao final)
            $pedidoId = DB::table('pedidos_fabrica')->insertGetId([
                'status'                 => 'pendente',
                'data_previsao_entrega'  => $dataPrevisao,
                'observacoes'            => Arr::random($observacoesPool),
                'created_at'             => $t0,
                'updated_at'             => $t0,
            ]);

            // itens (1..3)
            $idsVariacoes = collect($variacoes)->shuffle()->take(rand(1,3))->values()->all();
            $itens = [];
            foreach ($idsVariacoes as $idVar) {
                $qtd = rand(1, 10);
                $entregue = 0; // ajustaremos conforme status final

                $itens[] = [
                    'pedido_fabrica_id'   => $pedidoId,
                    'produto_variacao_id' => $idVar,
                    'quantidade'          => $qtd,
                    'quantidade_entregue' => $entregue,
                    'deposito_id'         => Arr::random($depositos),
                    'pedido_venda_id'     => fake()->optional(0.5)->randomElement($pedidosVenda),
                    'observacoes'         => fake()->optional()->sentence(),
                    'created_at'          => $t0,
                    'updated_at'          => $t0,
                ];
            }
            DB::table('pedidos_fabrica_itens')->insert($itens);

            // Ajuste de quantidades entregues conforme status final
            if ($statusFinal === 'parcial') {
                // Garante pelo menos um item parcial e nenhum item totalmente entregue (opção mais “realista”)
                $itensRows = DB::table('pedidos_fabrica_itens')->where('pedido_fabrica_id', $pedidoId)->get();
                $idxParcial = $itensRows->keys()->random();
                foreach ($itensRows as $k => $row) {
                    if ($k === $idxParcial) {
                        $parcial = max(1, min($row->quantidade - 1, rand(1, $row->quantidade - 1)));
                        DB::table('pedidos_fabrica_itens')
                            ->where('id', $row->id)
                            ->update(['quantidade_entregue' => $parcial, 'updated_at' => $t2]);
                    } else {
                        // pode deixar 0 entregue
                        DB::table('pedidos_fabrica_itens')
                            ->where('id', $row->id)
                            ->update(['quantidade_entregue' => 0, 'updated_at' => $t1]);
                    }
                }
            } elseif ($statusFinal === 'entregue') {
                // Pode ter passado por parcial antes do total
                $itensRows = DB::table('pedidos_fabrica_itens')->where('pedido_fabrica_id', $pedidoId)->get();
                foreach ($itensRows as $row) {
                    // simula que houve parcial antes (opcionalmente 30% dos itens)
                    $teveParcialAntes = fake()->boolean(30);
                    if ($teveParcialAntes && $row->quantidade > 1) {
                        $parcial = rand(1, $row->quantidade - 1);
                        DB::table('pedidos_fabrica_itens')->where('id', $row->id)->update([
                            'quantidade_entregue' => $parcial,
                            'updated_at' => $t2,
                        ]);
                    }
                    // finaliza total
                    DB::table('pedidos_fabrica_itens')->where('id', $row->id)->update([
                        'quantidade_entregue' => $row->quantidade,
                        'updated_at' => $t3,
                    ]);
                }
            }
            // enviados/pendentes/cancelados permanecem com entregue=0

            // Histórico em ordem
            $historicos = [];
            $pushHist = function (string $st, Carbon $ts) use (&$historicos, $pedidoId) {
                $historicos[] = [
                    'pedido_fabrica_id' => $pedidoId,
                    'status'            => $st,
                    'usuario_id'        => null,
                    'observacao'        => null,
                    'created_at'        => $ts,
                    'updated_at'        => $ts,
                ];
            };

            // pendente sempre primeiro
            $pushHist('pendente', $t0);

            if (in_array($statusFinal, ['enviado','parcial','entregue'], true)) {
                $pushHist('enviado', $t1);
            }

            if (in_array($statusFinal, ['parcial','entregue'], true)) {
                $pushHist('parcial', $t2);
            }

            if ($statusFinal === 'entregue') {
                $pushHist('entregue', $t3);
            }

            if ($statusFinal === 'cancelado') {
                // pode cancelar após pendente ou após enviado; aqui após pendente para simplificar
                $pushHist('cancelado', $t1);
            }

            DB::table('pedido_fabrica_status_historicos')->insert($historicos);

            // Atualiza status final do pedido e timestamps
            $ultimoTs = match ($statusFinal) {
                'pendente'  => $t0,
                'enviado'   => $t1,
                'parcial'   => $t2,
                'entregue'  => $t3,
                'cancelado' => $t1,
                default     => $t0,
            };

            DB::table('pedidos_fabrica')->where('id', $pedidoId)->update([
                'status'     => $statusFinal,
                'updated_at' => $ultimoTs,
            ]);
        }
    }

    /**
     * Escolhe um valor de $values baseado em pesos.
     * @param array<int, string> $values
     * @param array<int, int> $weights
     */
    private function weightedPick(array $values, array $weights): string
    {
        $sum = array_sum($weights);
        $r = rand(1, $sum);
        $acc = 0;
        foreach ($values as $i => $v) {
            $acc += $weights[$i];
            if ($r <= $acc) return $v;
        }
        return $values[array_key_first($values)];
    }
}
