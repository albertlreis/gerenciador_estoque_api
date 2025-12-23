<?php

namespace App\Services;

use App\Enums\ContaReceberStatusEnum;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ContaReceberService
{
    /**
     * Recalcula valor_recebido, saldo_aberto e status.
     * Regras:
     * - valor_recebido = soma(pagamentos.valor_pago)
     * - saldo_aberto = max(0, valor_liquido - valor_recebido)
     * - status:
     *   - saldo == 0 => RECEBIDO
     *   - valor_recebido > 0 => PARCIAL
     *   - saldo > 0 e vencido => VENCIDO
     *   - senão => ABERTO
     */
    public function recalcular(ContaReceber $conta, bool $salvar = true): ContaReceber
    {
        // soma pagamentos (inclui estornos como valor negativo)
        $recebido = (float) $conta->pagamentos()->sum('valor_pago');

        $conta->valor_recebido = $recebido;

        $liquido = (float) ($conta->valor_liquido ?? 0);
        $saldo = $liquido - $recebido;
        $conta->saldo_aberto = $saldo > 0 ? $saldo : 0;

        // Mantém status cancelado/estornado se já estiver assim
        $statusAtual = ContaReceberStatusEnum::fromDb($conta->status);

        if (in_array($statusAtual, [ContaReceberStatusEnum::CANCELADO, ContaReceberStatusEnum::ESTORNADO], true)) {
            if ($salvar) $conta->save();
            return $conta;
        }

        $hoje = now()->startOfDay();
        $venc = $conta->data_vencimento ? Carbon::parse($conta->data_vencimento)->startOfDay() : null;

        if ($conta->saldo_aberto <= 0.00001) {
            $conta->status = ContaReceberStatusEnum::RECEBIDO->value;
        } elseif ($conta->valor_recebido > 0) {
            $conta->status = ContaReceberStatusEnum::PARCIAL->value;
        } elseif ($venc && $venc->lt($hoje)) {
            $conta->status = ContaReceberStatusEnum::VENCIDO->value;
        } else {
            $conta->status = ContaReceberStatusEnum::ABERTO->value;
        }

        if ($salvar) {
            $conta->save();
        }

        return $conta;
    }

    /**
     * Cria uma nova conta a receber.
     * OBS: recalcula automaticamente após criar (caso venha com descontos/juros/multa).
     */
    public function criar(array $dados): ContaReceber
    {
        return DB::transaction(function () use ($dados) {

            $valorBruto = (float)($dados['valor_bruto'] ?? 0);
            $desconto   = (float)($dados['desconto'] ?? 0);
            $juros      = (float)($dados['juros'] ?? 0);
            $multa      = (float)($dados['multa'] ?? 0);

            // se não vier valor_liquido, calcula
            $valorLiquido = array_key_exists('valor_liquido', $dados)
                ? (float)$dados['valor_liquido']
                : max(0, ($valorBruto - $desconto + $juros + $multa));

            $conta = ContaReceber::create([
                'pedido_id'         => $dados['pedido_id'] ?? null,
                'descricao'         => $dados['descricao'] ?? "Conta a receber",
                'numero_documento'  => $dados['numero_documento'] ?? null,
                'data_emissao'      => $dados['data_emissao'] ?? now(),
                'data_vencimento'   => $dados['data_vencimento'] ?? now()->addDays(30),
                'valor_bruto'       => $valorBruto,
                'desconto'          => $desconto,
                'juros'             => $juros,
                'multa'             => $multa,
                'valor_liquido'     => $valorLiquido,
                'valor_recebido'    => 0,
                'saldo_aberto'      => $valorLiquido,
                'status'            => ContaReceberStatusEnum::ABERTO->value,
                'forma_recebimento' => $dados['forma_recebimento'] ?? null,
                'centro_custo'      => $dados['centro_custo'] ?? null,
                'categoria'         => $dados['categoria'] ?? null,
                'observacoes'       => $dados['observacoes'] ?? null,
            ]);

            // garante status correto se já nasceu vencida etc
            return $this->recalcular($conta, true);
        });
    }

    /**
     * Atualiza uma conta e recalcula automaticamente os campos derivados.
     */
    public function atualizar(ContaReceber $conta, array $dados): ContaReceber
    {
        return DB::transaction(function () use ($conta, $dados) {

            $valorBruto = array_key_exists('valor_bruto', $dados) ? (float)$dados['valor_bruto'] : (float)$conta->valor_bruto;
            $desconto   = array_key_exists('desconto', $dados) ? (float)$dados['desconto'] : (float)$conta->desconto;
            $juros      = array_key_exists('juros', $dados) ? (float)$dados['juros'] : (float)$conta->juros;
            $multa      = array_key_exists('multa', $dados) ? (float)$dados['multa'] : (float)$conta->multa;

            // Se veio valor_liquido explicitamente, respeita; senão recalcula.
            if (!array_key_exists('valor_liquido', $dados) && (
                    array_key_exists('valor_bruto', $dados) ||
                    array_key_exists('desconto', $dados) ||
                    array_key_exists('juros', $dados) ||
                    array_key_exists('multa', $dados)
                )) {
                $dados['valor_liquido'] = max(0, ($valorBruto - $desconto + $juros + $multa));
            }

            $conta->update($dados);

            return $this->recalcular($conta->fresh(), true);
        });
    }

    /**
     * Registra uma baixa de pagamento.
     */
    public function registrarBaixa(ContaReceber $conta, array $dados): ContaReceberPagamento
    {
        return DB::transaction(function () use ($conta, $dados) {

            $valorPago = (float) $dados['valor_pago'];
            $dataPagamento = Carbon::parse($dados['data_pagamento']);

            $pagamento = ContaReceberPagamento::create([
                'conta_receber_id' => $conta->id,
                'data_pagamento'   => $dataPagamento,
                'valor_pago'       => $valorPago,
                'forma_pagamento'  => $dados['forma_pagamento'] ?? 'Indefinido',
                'comprovante'      => $dados['comprovante'] ?? null,
            ]);

            $this->recalcular($conta->fresh(), true);

            return $pagamento;
        });
    }

    /**
     * Estorno automático + soft delete.
     * - Se existir valor_recebido > 0, cria um "pagamento" negativo (ESTORNO) para zerar recebidos e manter auditoria.
     * - Marca status como ESTORNADO e então executa soft delete.
     */
    public function remover(ContaReceber $conta, ?string $motivo = null): void
    {
        DB::transaction(function () use ($conta, $motivo) {

            $conta->load('pagamentos');

            $recebidoAtual = (float)$conta->pagamentos->sum('valor_pago');

            if ($recebidoAtual > 0.00001) {
                ContaReceberPagamento::create([
                    'conta_receber_id' => $conta->id,
                    'data_pagamento'   => now(),
                    'valor_pago'       => -1 * $recebidoAtual,
                    'forma_pagamento'  => 'ESTORNO',
                    'comprovante'      => null,
                ]);
            }

            // Força status de estorno e recalcula (vai zerar)
            $conta->status = ContaReceberStatusEnum::ESTORNADO->value;
            $conta->observacoes = trim(($conta->observacoes ? $conta->observacoes . "\n" : '') . ($motivo ? "Estorno/remoção: {$motivo}" : 'Estorno/remoção automática'));
            $conta->save();

            $this->recalcular($conta->fresh(), true);

            // soft delete
            $conta->delete();
        });
    }

    /**
     * Gera conta automaticamente ao finalizar um pedido.
     * (ajustado para seus campos do Pedido: valor_total, data_limite_entrega, numero_externo)
     */
    public function gerarPorPedido($pedido): ContaReceber
    {
        $valor = (float)($pedido->valor_total ?? 0);
        $dataVenc = $pedido->data_limite_entrega ?? now()->addDays(30);

        $conta = ContaReceber::create([
            'pedido_id'         => $pedido->id,
            'descricao'         => "Recebimento do Pedido #{$pedido->id}",
            'numero_documento'  => (string)($pedido->numero_externo ?? $pedido->id),
            'data_emissao'      => now(),
            'data_vencimento'   => $dataVenc,
            'valor_bruto'       => $valor,
            'desconto'          => 0,
            'juros'             => 0,
            'multa'             => 0,
            'valor_liquido'     => $valor,
            'valor_recebido'    => 0,
            'saldo_aberto'      => $valor,
            'status'            => ContaReceberStatusEnum::ABERTO->value,
            'forma_recebimento' => null,
            'centro_custo'      => 'Vendas',
            'categoria'         => 'Receitas',
        ]);

        return $this->recalcular($conta, true);
    }

    /**
     * Estorna uma conta sem removê-la:
     * - cria "pagamento" negativo para zerar o recebido (auditoria)
     * - marca status ESTORNADO
     * - recalcula saldo/recebido
     */
    public function estornar(ContaReceber $conta, ?string $motivo = null): void
    {
        DB::transaction(function () use ($conta, $motivo) {

            $conta->load('pagamentos');

            $recebidoAtual = (float) $conta->pagamentos->sum('valor_pago');

            if ($recebidoAtual > 0.00001) {
                ContaReceberPagamento::create([
                    'conta_receber_id' => $conta->id,
                    'data_pagamento'   => now(),
                    'valor_pago'       => -1 * $recebidoAtual,
                    'forma_pagamento'  => 'ESTORNO',
                    'comprovante'      => null,
                ]);
            }

            $conta->status = ContaReceberStatusEnum::ESTORNADO->value;
            $conta->observacoes = trim(
                ($conta->observacoes ? $conta->observacoes . "\n" : '')
                . ($motivo ? "Estorno: {$motivo}" : 'Estorno realizado via endpoint')
            );
            $conta->save();

            $this->recalcular($conta->fresh(), true);
        });
    }
}
