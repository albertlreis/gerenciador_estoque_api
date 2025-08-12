<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use App\Enums\PedidoStatus;
use App\Helpers\PedidoHelper;
use App\Models\Pedido;
use App\Services\PedidoPrazoService;

class PedidosSeeder extends Seeder
{
    public function run(): void
    {
        $clientes   = DB::table('clientes')->pluck('id')->toArray();
        $vendedores = DB::table('acesso_usuarios')
            ->whereIn('email', ['vendedor1@teste.com', 'vendedor2@teste.com', 'vendedor3@teste.com'])
            ->pluck('id')->toArray();
        $admins     = DB::table('acesso_usuarios')
            ->whereIn('email', ['admin@teste.com'])
            ->pluck('id')->toArray();
        $parceiros  = DB::table('parceiros')->pluck('id')->toArray();
        $variacoes  = DB::table('produto_variacoes')->pluck('id')->toArray();

        $now = CarbonImmutable::now('America/Belem');
        $itens = [];
        $statusHistorico = [];
        $numerosExternosUsados = [];

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

        /** @var PedidoPrazoService $pedidoPrazoService */
        $pedidoPrazoService = app(PedidoPrazoService::class);

        for ($i = 0; $i < 100; $i++) {
            $idCliente  = fake()->randomElement($clientes);
            $idUsuario  = fake()->randomElement($vendedores);
            $idParceiro = fake()->optional()->randomElement($parceiros);

            $tipo        = fake()->randomElement(['normal', 'via_estoque', 'consignado']);
            $viaEstoque  = $tipo === 'via_estoque';
            $consignado  = $tipo === 'consignado';

            // Define fluxo esperado
            $pedidoFake  = new Pedido(['via_estoque' => $viaEstoque, 'consignado' => $consignado]);
            $fluxoTipo   = PedidoHelper::fluxoPorTipo($pedidoFake);
            $statusOrdenados  = array_map(fn($s) => $s->value, $fluxoTipo);
            $maxStatusIndex   = fake()->biasedNumberBetween(0, count($statusOrdenados) - 1, fn () => 0.7);
            $statusAtual      = $statusOrdenados[$maxStatusIndex];

            // data_pedido: pode ser nula se ficou só em "PEDIDO_CRIADO"
            $dataPedido = $statusAtual === PedidoStatus::PEDIDO_CRIADO->value
                ? null
                : fake()->dateTimeBetween('-6 months');

            // número externo único
            do {
                $numeroExterno = fake()->numberBetween(1000, 9999);
            } while (in_array($numeroExterno, $numerosExternosUsados));
            $numerosExternosUsados[] = $numeroExterno;

            // Prazo (padrão 60, com variação pra dados mais realistas)
            $prazoDiasUteis = fake()->randomElement([45, 60, 60, 60, 90]);

            // Insere pedido (sem itens ainda)
            $pedidoId = DB::table('pedidos')->insertGetId([
                'id_cliente'          => $idCliente,
                'id_usuario'          => $idUsuario,
                'id_parceiro'         => $idParceiro,
                'numero_externo'      => $numeroExterno,
                'data_pedido'         => $dataPedido, // pode ser null
                'valor_total'         => 0,           // atualiza depois
                'observacoes'         => fake()->randomElement($observacoesPedidoPool),
                'prazo_dias_uteis'    => $prazoDiasUteis,
                'data_limite_entrega' => null,        // calcula depois se tiver data_pedido
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            // Itens do pedido
            $qtdItens = rand(1, 4);
            $valorTotal = 0;
            $variacoesSelecionadas = collect($variacoes)->shuffle()->take($qtdItens);

            foreach ($variacoesSelecionadas as $idVariacao) {
                $quantidade = rand(1, 5);
                $preco      = fake()->randomFloat(2, 300, 2000);
                $subtotal   = $quantidade * $preco;

                $entregaPendente = !$consignado && fake()->boolean(40);
                $observacoesEntrega = $entregaPendente
                    ? fake()->randomElement([
                        'Aguardando término da obra.',
                        'Entrega só após o dia 20.',
                        'Cliente pediu para reservar por tempo indeterminado.',
                        'Produto será retirado somente após reforma.',
                        'Entregar apenas quando o cliente avisar.',
                        null
                    ])
                    : null;

                $itens[] = [
                    'id_pedido'                    => $pedidoId,
                    'id_variacao'                  => $idVariacao,
                    'quantidade'                   => $quantidade,
                    'preco_unitario'               => $preco,
                    'subtotal'                     => $subtotal,
                    'entrega_pendente'             => $entregaPendente,
                    'data_liberacao_entrega'       => null,
                    'observacao_entrega_pendente'  => $observacoesEntrega,
                    'created_at'                   => $now,
                    'updated_at'                   => $now,
                ];

                $valorTotal += $subtotal;

                if ($consignado) {
                    DB::table('consignacoes')->insert([
                        'pedido_id'             => $pedidoId,
                        'produto_variacao_id'   => $idVariacao,
                        'deposito_id'           => fake()->numberBetween(1, 3),
                        'quantidade'            => $quantidade,
                        'data_envio'            => $dataPedido ?: $now,
                        'prazo_resposta'        => $now->addDays(15),
                        'status'                => 'pendente',
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ]);
                }
            }

            // Atualiza total do pedido
            DB::table('pedidos')->where('id', $pedidoId)->update(['valor_total' => $valorTotal]);

            // Histórico de status
            $dataStatus = $dataPedido
                ? CarbonImmutable::parse($dataPedido, 'America/Belem')
                : $now->subDays(rand(1, 180));

            for ($s = 0; $s <= $maxStatusIndex; $s++) {
                $status        = $statusOrdenados[$s];
                $responsavelId = fake()->boolean(80) ? $idUsuario : fake()->randomElement($admins);

                $statusHistorico[] = [
                    'pedido_id'   => $pedidoId,
                    'status'      => $status,
                    'data_status' => $dataStatus,
                    'usuario_id'  => $responsavelId,
                    'observacoes' => fake()->randomElement($observacoesStatusPool),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];

                $dataStatus = $dataStatus->addDays(rand(1, 3));
            }

            // >>> NOVO: calcular data_limite_entrega (se houver data_pedido)
            $pedido = Pedido::find($pedidoId);
            if ($pedido && $pedido->data_pedido) {
                // Usa o prazo já salvo; considera feriados BR + PA (config/holidays)
                $pedidoPrazoService->definirDataLimite($pedido, (int) $prazoDiasUteis);
            }
        }

        // Inserts em lote
        if (!empty($itens)) {
            DB::table('pedido_itens')->insert($itens);
        }
        if (!empty($statusHistorico)) {
            DB::table('pedido_status_historico')->insert($statusHistorico);
        }
    }
}
