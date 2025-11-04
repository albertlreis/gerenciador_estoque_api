<?php

namespace App\Services;

use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Exception;

class ContaReceberService
{
    /**
     * Cria uma nova conta a receber manualmente ou vinculada a um pedido.
     */
    public function criar(array $dados): ContaReceber
    {
        return ContaReceber::create([
            'pedido_id'        => $dados['pedido_id'] ?? null,
            'descricao'        => $dados['descricao'] ?? "Conta a receber",
            'numero_documento' => $dados['numero_documento'] ?? null,
            'data_emissao'     => $dados['data_emissao'] ?? now(),
            'data_vencimento'  => $dados['data_vencimento'] ?? now()->addDays(30),
            'valor_bruto'      => $dados['valor_bruto'] ?? 0,
            'desconto'         => $dados['desconto'] ?? 0,
            'juros'            => $dados['juros'] ?? 0,
            'multa'            => $dados['multa'] ?? 0,
            'valor_liquido'    => $dados['valor_liquido'] ?? $dados['valor_bruto'] ?? 0,
            'valor_recebido'   => 0,
            'saldo_aberto'     => $dados['valor_liquido'] ?? 0,
            'status'           => 'ABERTO',
            'forma_recebimento'=> $dados['forma_recebimento'] ?? null,
            'centro_custo'     => $dados['centro_custo'] ?? null,
            'categoria'        => $dados['categoria'] ?? null,
            'observacoes'      => $dados['observacoes'] ?? null,
        ]);
    }

    /**
     * Registra uma baixa de pagamento.
     */
    public function registrarBaixa(ContaReceber $conta, array $dados): ContaReceberPagamento
    {
        return DB::transaction(function() use ($conta, $dados) {

            $valorPago = (float) $dados['valor_pago'];
            $dataPagamento = Carbon::parse($dados['data_pagamento']);

            // Cria o registro de pagamento
            $pagamento = ContaReceberPagamento::create([
                'conta_receber_id' => $conta->id,
                'data_pagamento'   => $dataPagamento,
                'valor_pago'       => $valorPago,
                'forma_pagamento'  => $dados['forma_pagamento'] ?? 'Indefinido',
                'comprovante'      => $dados['comprovante'] ?? null,
            ]);

            // Atualiza totais da conta
            $conta->valor_recebido += $valorPago;
            $conta->saldo_aberto = max(0, $conta->valor_liquido - $conta->valor_recebido);

            if ($conta->saldo_aberto == 0) {
                $conta->status = 'PAGO';
            } elseif ($conta->valor_recebido > 0) {
                $conta->status = 'PARCIAL';
            }

            $conta->save();

            return $pagamento;
        });
    }

    /**
     * Gera conta automaticamente ao finalizar um pedido.
     */
    public function gerarPorPedido($pedido): ContaReceber
    {
        $valor = $pedido->total ?? 0;
        $dataVenc = $pedido->data_entrega ?? now()->addDays(30);

        return ContaReceber::create([
            'pedido_id'        => $pedido->id,
            'descricao'        => "Recebimento do Pedido #{$pedido->numero}",
            'numero_documento' => $pedido->numero,
            'data_emissao'     => now(),
            'data_vencimento'  => $dataVenc,
            'valor_bruto'      => $valor,
            'valor_liquido'    => $valor,
            'saldo_aberto'     => $valor,
            'status'           => 'ABERTO',
            'forma_recebimento'=> $pedido->forma_pagamento ?? 'A Definir',
            'centro_custo'     => 'Vendas',
            'categoria'        => 'Receitas',
        ]);
    }
}
