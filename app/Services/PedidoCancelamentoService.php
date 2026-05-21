<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\ContaReceber;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PedidoCancelamentoService
{
    public function __construct(
        private readonly ReservaEstoqueService $reservas,
        private readonly EstoqueMovimentacaoService $movimentacoes,
    ) {}

    public function cancelar(Pedido $pedido, array $opcoes, ?int $usuarioId): array
    {
        return DB::transaction(function () use ($pedido, $opcoes, $usuarioId) {
            $pedido = Pedido::query()
                ->with(['statusAtual'])
                ->lockForUpdate()
                ->findOrFail($pedido->id);

            $statusAtual = $pedido->statusAtual?->status?->value ?? $pedido->statusAtual?->status;
            if ($statusAtual === PedidoStatus::CANCELADO->value) {
                throw ValidationException::withMessages([
                    'pedido' => ['Pedido ja esta cancelado.'],
                ]);
            }

            $resultado = [
                'reservas_canceladas' => 0,
                'movimentacoes_estornadas' => 0,
                'contas_canceladas' => 0,
            ];

            if (!empty($opcoes['cancelar_reservas'])) {
                $resultado['reservas_canceladas'] = $this->cancelarReservas($pedido, $usuarioId);
            }

            if (!empty($opcoes['estornar_estoque'])) {
                $resultado['movimentacoes_estornadas'] = $this->estornarMovimentacoesDoPedido($pedido, $usuarioId);
            }

            if (!empty($opcoes['cancelar_financeiro'])) {
                $resultado['contas_canceladas'] = $this->cancelarContasReceber($pedido);
            }

            $pedido->historicoStatus()->create([
                'status' => PedidoStatus::CANCELADO,
                'observacoes' => $opcoes['observacoes'] ?? null,
                'data_status' => now(),
                'usuario_id' => $usuarioId,
            ]);

            logAuditoria('pedido_cancelamento', "Pedido #{$pedido->id} cancelado.", [
                'acao' => 'cancelar_venda',
                'nivel' => 'warn',
                'opcoes' => [
                    'cancelar_reservas' => (bool) ($opcoes['cancelar_reservas'] ?? false),
                    'estornar_estoque' => (bool) ($opcoes['estornar_estoque'] ?? false),
                    'cancelar_financeiro' => (bool) ($opcoes['cancelar_financeiro'] ?? false),
                ],
                'resultado' => $resultado,
            ], $pedido);

            return $resultado;
        });
    }

    private function cancelarReservas(Pedido $pedido, ?int $usuarioId): int
    {
        $ativas = $pedido->id
            ? EstoqueReserva::query()
                ->where('pedido_id', $pedido->id)
                ->where('status', 'ativa')
                ->count()
            : 0;

        $this->reservas->cancelarPorPedido((int) $pedido->id, $usuarioId, 'pedido_cancelado');

        return (int) $ativas;
    }

    private function estornarMovimentacoesDoPedido(Pedido $pedido, ?int $usuarioId): int
    {
        $tiposSaida = [
            EstoqueMovimentacaoTipo::SAIDA->value,
            EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
        ];

        $movimentacoes = EstoqueMovimentacao::query()
            ->where('pedido_id', $pedido->id)
            ->whereIn('tipo', $tiposSaida)
            ->whereNotNull('id_deposito_origem')
            ->get();

        $estornadas = 0;
        foreach ($movimentacoes as $movimentacao) {
            $jaEstornada = EstoqueMovimentacao::query()
                ->where('ref_type', 'estorno')
                ->where('ref_id', $movimentacao->id)
                ->exists();

            if ($jaEstornada) {
                continue;
            }

            $this->movimentacoes->estornarMovimentacao(
                (int) $movimentacao->id,
                $usuarioId,
                "Cancelamento do Pedido #{$pedido->id}"
            );
            $estornadas++;
        }

        return $estornadas;
    }

    private function cancelarContasReceber(Pedido $pedido): int
    {
        $contas = ContaReceber::query()
            ->where('pedido_id', $pedido->id)
            ->get();

        $contasComPagamento = $contas->filter(fn (ContaReceber $conta) => $conta->pagamentos()->exists());
        if ($contasComPagamento->isNotEmpty()) {
            throw ValidationException::withMessages([
                'financeiro' => ['Existem contas a receber com pagamento registrado. Estorne os recebimentos antes de cancelar o financeiro.'],
            ]);
        }

        $canceladas = 0;
        foreach ($contas as $conta) {
            $status = $conta->status?->value ?? $conta->status;
            if ($status === ContaStatus::CANCELADA->value) {
                continue;
            }

            $conta->status = ContaStatus::CANCELADA;
            $conta->observacoes = trim((string) ($conta->observacoes ?? '') . "\nCancelada pelo cancelamento do Pedido #{$pedido->id}.");
            $conta->save();
            $canceladas++;
        }

        return $canceladas;
    }
}
