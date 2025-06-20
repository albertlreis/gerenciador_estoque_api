<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Enums\PedidoStatus;

class PedidosSeeder extends Seeder
{
    public function run(): void
    {
        $clientes = DB::table('clientes')->pluck('id')->toArray();
        $vendedores = DB::table('acesso_usuarios')
            ->whereIn('email', ['vendedor1@teste.com', 'vendedor2@teste.com', 'vendedor3@teste.com'])
            ->pluck('id')
            ->toArray();
        $admins = DB::table('acesso_usuarios')
            ->whereIn('email', ['admin@teste.com'])
            ->pluck('id')
            ->toArray();

        $parceiros = DB::table('parceiros')->pluck('id')->toArray();
        $variacoes = DB::table('produto_variacoes')->pluck('id')->toArray();
        $statusEnum = PedidoStatus::cases();
        $now = Carbon::now();

        $itens = [];
        $statusHistorico = [];

        $observacoesPedidoPool = [
            'Cliente solicitou entrega após o dia 10.',
            'Verificar disponibilidade de tecido antes da produção.',
            'Pedido com urgência, combinar transporte direto.',
            'Cliente pediu acabamento em tom mais escuro.',
            'Aguardando confirmação de endereço de entrega.',
            'Solicitado ajuste no modelo da cadeira antes da produção.',
            'Desconto de 10% aplicado conforme negociação.',
            'Parceria com arquiteto, comissionamento incluso.',
            'Cliente deseja ser avisado antes do envio.',
            'Montagem será feita por equipe terceirizada.',
            null,
        ];

        $observacoesStatusPool = [
            'Pedido registrado no sistema.',
            'Encaminhado para o setor de produção.',
            'Nota fiscal emitida com sucesso.',
            'Data de embarque prevista para próxima semana.',
            'Produto coletado pela transportadora.',
            'Nota recebida e validada.',
            'Estoque atualizado após recebimento.',
            'Separação para envio ao cliente em andamento.',
            'Entrega programada para amanhã.',
            'Cliente confirmou o recebimento sem avarias.',
            'Consignado entregue conforme agendamento.',
            'Produto devolvido pelo cliente, aguardando vistoria.',
            'Pedido encerrado com sucesso.',
            null,
        ];

        for ($i = 0; $i < 100; $i++) {
            $idCliente = fake()->randomElement($clientes);
            $idUsuario = fake()->randomElement($vendedores);
            $idParceiro = fake()->optional()->randomElement($parceiros);

            $maxStatusIndex = fake()->biasedNumberBetween(0, count($statusEnum) - 1, fn () => 0.7);
            $statusAtual = $statusEnum[$maxStatusIndex]->value;
            $dataPedido = $statusAtual === PedidoStatus::PEDIDO_CRIADO->value
                ? null
                : fake()->dateTimeBetween('-6 months');

            $pedidoId = DB::table('pedidos')->insertGetId([
                'id_cliente' => $idCliente,
                'id_usuario' => $idUsuario,
                'id_parceiro' => $idParceiro,
                'numero_externo' => fake()->numberBetween(1000, 9999),
                'data_pedido' => $dataPedido,
                'valor_total' => 0,
                'observacoes' => fake()->randomElement($observacoesPedidoPool),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Itens do pedido
            $qtdItens = rand(1, 4);
            $valorTotal = 0;
            $variacoesSelecionadas = collect($variacoes)->shuffle()->take($qtdItens);

            foreach ($variacoesSelecionadas as $idVariacao) {
                $quantidade = rand(1, 5);
                $preco = fake()->randomFloat(2, 300, 2000);
                $subtotal = $quantidade * $preco;

                $itens[] = [
                    'id_pedido' => $pedidoId,
                    'id_variacao' => $idVariacao,
                    'quantidade' => $quantidade,
                    'preco_unitario' => $preco,
                    'subtotal' => $subtotal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $valorTotal += $subtotal;
            }

            DB::table('pedidos')->where('id', $pedidoId)->update(['valor_total' => $valorTotal]);

            // Histórico de status
            $dataStatus = $dataPedido ? Carbon::parse($dataPedido)->copy() : $now->copy()->subDays(rand(1, 180));

            for ($s = 0; $s <= $maxStatusIndex; $s++) {
                $responsavelId = fake()->boolean(80)
                    ? $idUsuario // 80% das vezes o próprio vendedor
                    : fake()->randomElement($admins); // 20% das vezes um admin

                $statusHistorico[] = [
                    'pedido_id' => $pedidoId,
                    'status' => $statusEnum[$s]->value,
                    'data_status' => $dataStatus->copy(),
                    'usuario_id' => $responsavelId,
                    'observacoes' => fake()->randomElement($observacoesStatusPool),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $dataStatus->addDays();
            }
        }

        DB::table('pedido_itens')->insert($itens);
        DB::table('pedido_status_historico')->insert($statusHistorico);
    }
}
