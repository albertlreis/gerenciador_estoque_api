<?php

namespace App\Services;

use App\Models\Devolucao;
use App\Models\Credito;
use App\Models\Estoque;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Serviço para gerenciar o fluxo de devoluções e trocas de pedidos.
 */
class DevolucaoService
{
    /**
     * Inicia uma devolução ou troca para um pedido.
     *
     * @param  array  $data  Dados da devolução:
     *                       - pedido_id (int)
     *                       - tipo (string: troca|credito)
     *                       - motivo (string)
     *                       - itens (array):
     *                           - pedido_item_id (int)
     *                           - quantidade (int)
     *                           - trocas (array, se tipo=“troca”):
     *                               - nova_variacao_id (int)
     *                               - quantidade (int)
     *                               - preco_unitario (float)
     * @return Devolucao
     *
     * @throws \Throwable
     */
    public function iniciar(array $data): Devolucao
    {
        return DB::transaction(function() use ($data) {
            $dev = Devolucao::create([
                'pedido_id' => $data['pedido_id'],
                'tipo'      => $data['tipo'],
                'motivo'    => $data['motivo'],
            ]);

            foreach ($data['itens'] as $item) {
                $devItem = $dev->itens()->create([
                    'pedido_item_id' => $item['pedido_item_id'],
                    'quantidade'     => $item['quantidade'],
                ]);

                if ($data['tipo'] === 'troca') {
                    foreach ($item['trocas'] as $t) {
                        $devItem->trocaItens()->create([
                            'nova_variacao_id' => $t['nova_variacao_id'],
                            'quantidade'       => $t['quantidade'],
                            'preco_unitario'   => $t['preco_unitario'],
                        ]);
                    }
                }
            }

            return $dev->load('itens.trocaItens');
        });
    }

    /**
     * Aprova uma devolução pendente, atualiza estoques e gera créditos/trocas.
     *
     * @param  int  $devolucaoId
     * @return void
     *
     * @throws \Throwable
     */
    public function aprovar(int $devolucaoId): void
    {
        DB::transaction(function() use ($devolucaoId) {
            $dev = Devolucao::with([
                'itens.pedidoItem.variacao',
                'itens.trocaItens',
                'pedido.cliente'
            ])->findOrFail($devolucaoId);

            if ($dev->status !== 'pendente') {
                throw new Exception("Apenas devoluções pendentes podem ser aprovadas.");
            }

            $dev->status = 'aprovado';
            $dev->save();

            foreach ($dev->itens as $dItem) {
                $origVar = $dItem->pedidoItem->variacao;
                $this->ajustarEstoque($origVar->id, $dItem->quantidade, +1);

                if ($dev->tipo === 'troca') {
                    $totalOrig = $origVar->preco * $dItem->quantidade;
                    $totalNovo = 0;

                    foreach ($dItem->trocaItens as $t) {
                        $this->ajustarEstoque($t->nova_variacao_id, $t->quantidade, -1);
                        $totalNovo += $t->preco_unitario * $t->quantidade;
                    }

                    if ($totalNovo < $totalOrig) {
                        Credito::create([
                            'devolucao_id' => $dev->id,
                            'cliente_id'   => $dev->pedido->id_cliente,
                            'valor'        => $totalOrig - $totalNovo,
                            'data_validade'=> now()->addYear(),
                        ]);
                    }
                }

                if ($dev->tipo === 'credito') {
                    $valor = $origVar->preco * $dItem->quantidade;
                    Credito::create([
                        'devolucao_id' => $dev->id,
                        'cliente_id'   => $dev->pedido->id_cliente,
                        'valor'        => $valor,
                        'data_validade'=> now()->addYear(),
                    ]);
                }
            }

            $dev->status = 'concluido';
            $dev->save();
        });
    }

    /**
     * Recusa uma devolução pendente.
     *
     * @param int $devolucaoId
     * @return void
     * @throws \Exception
     */
    public function recusar(int $devolucaoId): void
    {
        $dev = Devolucao::findOrFail($devolucaoId);

        if ($dev->status !== 'pendente') {
            throw new Exception("Somente devoluções pendentes podem ser recusadas.");
        }

        $dev->status = 'recusado';
        $dev->save();
    }

    /**
     * Ajusta o estoque de uma variação de produto.
     *
     * @param  int  $variacaoId
     * @param  int  $quantidade
     * @param  int  $sinal  +1 para entrada, -1 para saída
     * @return void
     */
    protected function ajustarEstoque(int $variacaoId, int $quantidade, int $sinal): void
    {
        $depositoId = config('app.deposito_padrao');
        $estoque = Estoque::firstOrNew([
            'id_variacao' => $variacaoId,
            'id_deposito' => $depositoId,
        ]);

        $estoque->quantidade = ($estoque->quantidade ?? 0) + ($quantidade * $sinal);
        $estoque->save();
    }
}
