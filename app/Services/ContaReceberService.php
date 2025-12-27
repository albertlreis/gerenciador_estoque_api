<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaReceber;
use App\Models\Pedido;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ContaReceberService
{
    public function __construct(
        private readonly ContaReceberCommandService $cmd,
    ) {}

    /**
     * Gera 1 conta a receber para o pedido (idempotente).
     * - Se já existir conta_receber para o pedido, retorna a existente.
     * - Cria com status ABERTA e saldo_aberto = valor_liquido.
     */
    public function gerarPorPedido(Pedido $pedido): ContaReceber
    {
        return DB::transaction(function () use ($pedido) {

            // Garante valores atualizados (ex.: data_limite_entrega recém calculada)
            $pedido = $pedido->fresh();

            // Idempotência: evita duplicar em retry/duplo clique
            $existente = ContaReceber::query()
                ->where('pedido_id', $pedido->id)
                ->orderBy('id')
                ->first();

            if ($existente) return $existente;

            $total = (float) ($pedido->valor_total ?? 0);
            abort_if($total <= 0, 422, 'Pedido sem valor_total não pode gerar conta a receber.');

            $dataEmissao = $pedido->data_pedido
                ? Carbon::parse($pedido->data_pedido)
                : now('America/Belem');

            // Regra de vencimento:
            // 1) se existir data_limite_entrega do pedido, usa ela
            // 2) senão, usa +30 dias (configurável)
            $diasPadrao = (int) config('financeiro.contas_receber.dias_vencimento', 30);

            $dataVenc = $pedido->data_limite_entrega
                ? Carbon::parse($pedido->data_limite_entrega)->startOfDay()
                : $dataEmissao->copy()->addDays($diasPadrao)->startOfDay();

            // Categoria receita (preferência por 'vendas')
            $cat = CategoriaFinanceira::query()
                ->where('tipo', 'receita')
                ->whereIn('slug', ['vendas', 'receitas'])
                ->orderByRaw("slug = 'vendas' desc")
                ->first()
                ?? CategoriaFinanceira::query()
                    ->where('tipo', 'receita')
                    ->orderBy('ordem')
                    ->first();

            // Centro de custo (preferência por 'vendas', senão padrao)
            $cc = CentroCusto::query()
                ->whereIn('slug', ['vendas'])
                ->first()
                ?? CentroCusto::query()->where('padrao', 1)->first()
                ?? CentroCusto::query()->where('ativo', 1)->orderBy('ordem')->first();

            $numeroDoc = $pedido->numero_externo ?: "PED-{$pedido->id}";
            $descricao = "Recebimento Pedido #{$pedido->id}";

            $valorLiquido  = $total;
            $valorRecebido = 0.0;
            $saldoAberto   = $valorLiquido;

            return $this->cmd->criar([
                'pedido_id'        => (int) $pedido->id,
                'descricao'        => $descricao,
                'numero_documento' => $numeroDoc,
                'data_emissao'     => $dataEmissao->toDateString(),
                'data_vencimento'  => $dataVenc->toDateString(),

                'valor_bruto'      => $total,
                'desconto'         => 0,
                'juros'            => 0,
                'multa'            => 0,
                'valor_liquido'    => $valorLiquido,

                'valor_recebido'   => $valorRecebido,
                'saldo_aberto'     => $saldoAberto,

                'status'           => ContaStatus::ABERTA->value,
                'forma_recebimento'=> config('financeiro.contas_receber.forma_padrao', 'PIX'),

                'categoria_id'     => $cat?->id,
                'centro_custo_id'  => $cc?->id,

                'observacoes'      => null,
            ]);
        });
    }
}
