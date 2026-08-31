<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Models\ProdutoEntregaItem;
use App\Services\EstoqueDisponibilidadeService;
use App\Services\PedidoTipoConversaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconciliarConversaoTipoPedidoCommand extends Command
{
    protected $signature = 'pedidos:reconciliar-conversao-tipo
        {pedido : ID ou número externo do pedido}
        {--modo=entrega_confirmada : entrega_confirmada ou entrega_pendente}
        {--data= : Data efetiva da entrega (AAAA-MM-DD)}
        {--aplicar : Executa a reconciliação; sem esta opção é somente diagnóstico}
        {--confirmacao= : Número externo ou ID do pedido, obrigatório para aplicar}';

    protected $description = 'Diagnostica e reconcilia pedidos convertidos de reposição para venda sem baixa/entrega operacional.';

    public function handle(
        PedidoTipoConversaoService $conversao,
        EstoqueDisponibilidadeService $disponibilidade
    ): int {
        $identificador = trim((string) $this->argument('pedido'));
        $pedido = Pedido::query()
            ->where(function ($query) use ($identificador) {
                if (ctype_digit($identificador)) {
                    $query->whereKey((int) $identificador);
                }
                $query->orWhere('numero_externo', $identificador);
            })
            ->first();

        if (! $pedido) {
            $this->error("Pedido {$identificador} não encontrado.");
            return self::FAILURE;
        }

        if (! $pedido->isVenda() || ! $this->possuiConversaoAuditada($pedido)) {
            $this->error('O pedido não é uma venda com conversão auditada de reposição para venda.');
            return self::FAILURE;
        }

        $modo = (string) $this->option('modo');
        if (! in_array($modo, [PedidoTipoConversaoService::MODO_PENDENTE, PedidoTipoConversaoService::MODO_CONFIRMADA], true)) {
            $this->error('Modo inválido. Use entrega_pendente ou entrega_confirmada.');
            return self::FAILURE;
        }

        $itens = $pedido->entregaItens()
            ->with(['pedidoItem', 'variacao.produto'])
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('status', '!=', ProdutoEntregaItem::STATUS_CANCELADO)
            ->orderBy('id')
            ->get();
        $alocacoes = collect();
        $bloqueios = collect();

        foreach ($itens as $item) {
            $pendente = max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue);
            if ($pendente === 0) {
                continue;
            }

            $depositoId = (int) ($item->pedidoItem?->id_deposito ?: $item->id_deposito_destino ?: $item->id_deposito_origem);
            $saldo = $depositoId > 0
                ? $disponibilidade->getDisponivel((int) $item->id_variacao, $depositoId)
                : 0;
            $jaExpedidoNaoEntregue = max(0, (int) $item->quantidade_expedida - (int) $item->quantidade_entregue);
            $aExpedir = max(0, $pendente - $jaExpedidoNaoEntregue);
            if ($modo === PedidoTipoConversaoService::MODO_CONFIRMADA && ($depositoId <= 0 || $saldo < $aExpedir)) {
                $bloqueios->push("Item {$item->id}: depósito ausente ou saldo insuficiente ({$saldo}/{$aExpedir} a expedir).");
            }

            $alocacoes->push([
                'produto_entrega_item_id' => (int) $item->id,
                'produto' => $item->variacao?->produto?->nome,
                'id_deposito' => $depositoId,
                'quantidade' => $pendente,
                'a_expedir' => $aExpedir,
                'saldo_disponivel' => $saldo,
                'recebida' => (int) $item->quantidade_recebida,
                'expedida' => (int) $item->quantidade_expedida,
                'entregue' => (int) $item->quantidade_entregue,
            ]);
        }

        $rotulo = (string) ($pedido->numero_externo ?: $pedido->id);
        $this->info($this->option('aplicar')
            ? 'Diagnóstico prévio concluído; a aplicação ocorrerá somente após todas as validações.'
            : 'Diagnóstico em dry-run; nenhum registro foi alterado.');
        $this->table(
            ['Item', 'Produto', 'Depósito', 'Pendente', 'A expedir', 'Saldo', 'Recebida', 'Expedida', 'Entregue'],
            $alocacoes->map(fn (array $item) => [
                $item['produto_entrega_item_id'],
                $item['produto'],
                $item['id_deposito'],
                $item['quantidade'],
                $item['a_expedir'],
                $item['saldo_disponivel'],
                $item['recebida'],
                $item['expedida'],
                $item['entregue'],
            ])->all()
        );

        if ($alocacoes->isEmpty()) {
            $this->info('O pedido já está integralmente entregue; nenhuma correção é necessária.');
            return self::SUCCESS;
        }
        if ($bloqueios->isNotEmpty()) {
            $bloqueios->each(fn (string $bloqueio) => $this->error($bloqueio));
            return self::FAILURE;
        }
        if (! $this->option('aplicar')) {
            $this->line("Para aplicar: php artisan pedidos:reconciliar-conversao-tipo {$identificador} --modo={$modo} --data=AAAA-MM-DD --aplicar --confirmacao={$rotulo}");
            return self::SUCCESS;
        }
        if (trim((string) $this->option('confirmacao')) !== $rotulo) {
            $this->error("Confirmação inválida. Informe --confirmacao={$rotulo}.");
            return self::FAILURE;
        }
        if ($modo === PedidoTipoConversaoService::MODO_CONFIRMADA && ! $this->option('data')) {
            $this->error('Informe --data=AAAA-MM-DD para registrar a entrega confirmada.');
            return self::FAILURE;
        }

        try {
            $resultado = $conversao->aplicar($pedido, [
                'modo' => $modo,
                'ocorrido_em' => $this->option('data'),
                'idempotency_key' => "reconciliacao-conversao:{$pedido->id}:{$modo}:".($this->option('data') ?: 'pendente'),
                'itens' => $alocacoes->map(fn (array $item) => [
                    'produto_entrega_item_id' => $item['produto_entrega_item_id'],
                    'id_deposito' => $item['id_deposito'],
                    'quantidade' => $item['quantidade'],
                ])->all(),
            ], null);
        } catch (Throwable $exception) {
            $this->error('Falha ao aplicar a reconciliação: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reconciliação aplicada: %d evento(s), %d movimentação(ões).',
            count($resultado['eventos'] ?? []),
            count($resultado['movimentacoes'] ?? [])
        ));
        return self::SUCCESS;
    }

    private function possuiConversaoAuditada(Pedido $pedido): bool
    {
        return DB::table('auditoria_log_mudancas as mudanca')
            ->join('auditoria_logs as log', 'log.id', '=', 'mudanca.auditoria_log_id')
            ->where('log.entity_id', (string) $pedido->id)
            ->where('mudanca.campo', 'tipo')
            ->get(['log.entity_type', 'mudanca.old_value', 'mudanca.new_value'])
            ->contains(fn ($mudanca) =>
                $this->tipoEntidadeEhPedido((string) $mudanca->entity_type)
                && trim((string) $mudanca->old_value, '"') === Pedido::TIPO_REPOSICAO
                && trim((string) $mudanca->new_value, '"') === Pedido::TIPO_VENDA
            );
    }

    private function tipoEntidadeEhPedido(string $tipo): bool
    {
        $tipo = str_replace('\\\\', '\\', trim($tipo));

        return $tipo === Pedido::class || str_ends_with($tipo, '\\Pedido');
    }
}
