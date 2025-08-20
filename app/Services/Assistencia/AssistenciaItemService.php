<?php

namespace App\Services\Assistencia;

use App\DTOs\Assistencia\AdicionarItemDTO;
use App\DTOs\Assistencia\AprovacaoDTO;
use App\DTOs\Assistencia\EnviarItemAssistenciaDTO;
use App\DTOs\Assistencia\OrcamentoDTO;
use App\DTOs\Assistencia\RetornoDTO;
use App\Enums\AprovacaoOrcamento;
use App\Enums\AssistenciaStatus;
use App\Models\AssistenciaChamado;
use App\Models\AssistenciaChamadoItem;
use App\Models\AssistenciaChamadoLog;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssistenciaItemService
{
    public function __construct(
        protected AssistenciaChamadoService $chamadoService,
        protected EstoqueMovimentacaoService $estoqueMovimentacaoService
    ) {}

    /**
     * Adiciona um item ao chamado (status inicial: EM_ANALISE).
     */
    public function adicionarItem(AdicionarItemDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            $chamado = AssistenciaChamado::query()->findOrFail($dto->chamadoId);

            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->create([
                'chamado_id'              => $chamado->id,
                'produto_id'              => $dto->produtoId,
                'variacao_id'             => $dto->variacaoId,
                'numero_serie'            => $dto->numeroSerie,
                'lote'                    => $dto->lote,
                'defeito_id'              => $dto->defeitoId,
                'descricao_defeito_livre' => $dto->descricaoDefeitoLivre,
                'status_item'             => AssistenciaStatus::EM_ANALISE->value,
                'pedido_id'               => $dto->pedidoId,
                'pedido_item_id'          => $dto->pedidoItemId,
                'consignacao_id'          => $dto->consignacaoId,
                'deposito_origem_id'      => $dto->depositoOrigemId,
                'assistencia_id'          => $chamado->assistencia_id,
                'observacoes'             => $dto->observacoes,
            ]);

            $this->log(
                chamadoId: $chamado->id,
                itemId: $item->id,
                statusDe: null,
                statusPara: AssistenciaStatus::EM_ANALISE->value,
                mensagem: 'Item adicionado ao chamado',
                meta: ['usuario_id' => $usuarioId]
            );

            // Ajuste de SLA do chamado (se aplicável)
            $this->chamadoService->recalcularSLA($chamado);

            return $item->fresh(['defeito', 'variacao', 'produto']);
        });
    }

    /**
     * Envia item para assistência.
     * Regras: item deve estar ABERTO/EM_ANALISE. Movimenta estoque: origem -> depósito assistência (quantidade 1).
     */
    public function enviarParaAssistencia(EnviarItemAssistenciaDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()
                ->with('chamado')
                ->lockForUpdate()
                ->findOrFail($dto->itemId);

            $statusAtual = $this->statusValue($item->status_item);

            if (!in_array($statusAtual, [
                AssistenciaStatus::ABERTO->value,
                AssistenciaStatus::EM_ANALISE->value,
            ], true)) {
                throw new InvalidArgumentException('Item não está apto para envio à assistência.');
            }

            if (!$item->variacao_id) {
                throw new InvalidArgumentException('Variação obrigatória para movimentar estoque.');
            }
            if (!$item->deposito_origem_id) {
                throw new InvalidArgumentException('Depósito de origem obrigatório.');
            }
            if (empty($dto->assistenciaId)) {
                throw new InvalidArgumentException('Assistência obrigatória.');
            }
            if (empty($dto->depositoAssistenciaId)) {
                throw new InvalidArgumentException('Depósito da assistência obrigatório.');
            }

            // Movimentação de estoque (usa o serviço público)
            $this->estoqueMovimentacaoService->registrarEnvioAssistencia(
                variacaoId: $item->variacao_id,
                depositoOrigemId: $item->deposito_origem_id,
                depositoAssistenciaId: $dto->depositoAssistenciaId,
                quantidade: 1,
                usuarioId: $usuarioId,
                observacao: "Envio p/ assistência – Chamado #{$item->chamado->numero} / Item {$item->id}"
            );

            // Atualizações do item
            $item->update([
                'assistencia_id'         => $dto->assistenciaId,
                'deposito_assistencia_id'=> $dto->depositoAssistenciaId,
                'rastreio_envio'         => $dto->rastreioEnvio ? trim($dto->rastreioEnvio) : null,
                'data_envio'             => $dto->dataEnvio ?: now()->toDateString(),
                'status_item'            => AssistenciaStatus::ENVIADO_ASSISTENCIA->value,
            ]);

            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $statusAtual,
                statusPara: AssistenciaStatus::ENVIADO_ASSISTENCIA->value,
                mensagem: 'Item enviado para assistência',
                meta: ['usuario_id' => $usuarioId, 'rastreio' => $dto->rastreioEnvio]
            );

            // Opcional: avançar status do chamado conforme itens
            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);

            return $item->fresh();
        });
    }

    /**
     * Registra orçamento retornado pela assistência (status: EM_ORCAMENTO).
     */
    public function registrarOrcamento(OrcamentoDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->lockForUpdate()->findOrFail($dto->itemId);

            if ($dto->valorOrcado <= 0) {
                throw new InvalidArgumentException('Valor de orçamento inválido.');
            }

            $item->update([
                'valor_orcado' => $dto->valorOrcado,
                'aprovacao'    => AprovacaoOrcamento::PENDENTE->value,
                'status_item'  => AssistenciaStatus::EM_ORCAMENTO->value,
            ]);

            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $this->statusValue($item->status_item),
                statusPara: AssistenciaStatus::EM_ORCAMENTO->value,
                mensagem: 'Orçamento registrado',
                meta: ['usuario_id' => $usuarioId, 'valor' => $dto->valorOrcado]
            );

            return $item->fresh();
        });
    }

    /**
     * Aprova/Reprova orçamento.
     * - Se aprovado: status -> EM_REPARO, aprovacão = APROVADO, data_aprovacao = hoje.
     * - Se reprovado: aprovação = REPROVADO (mantém EM_ORCAMENTO; outra ação definirá substituição/devolução).
     */
    public function decidirOrcamento(AprovacaoDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->lockForUpdate()->findOrFail($dto->itemId);

            if ($this->statusValue($item->status_item) !== AssistenciaStatus::EM_ORCAMENTO->value) {
                throw new InvalidArgumentException('Item não está em orçamento.');
            }

            if ($dto->aprovado) {
                $item->update([
                    'aprovacao'     => AprovacaoOrcamento::APROVADO->value,
                    'data_aprovacao'=> now()->toDateString(),
                    'status_item'   => AssistenciaStatus::EM_REPARO->value,
                ]);

                $this->log(
                    chamadoId: $item->chamado_id,
                    itemId: $item->id,
                    statusDe: AssistenciaStatus::EM_ORCAMENTO->value,
                    statusPara: AssistenciaStatus::EM_REPARO->value,
                    mensagem: 'Orçamento aprovado',
                    meta: ['usuario_id' => $usuarioId, 'observacao' => $dto->observacao]
                );
            } else {
                $item->update([
                    'aprovacao'     => AprovacaoOrcamento::REPROVADO->value,
                    'data_aprovacao'=> now()->toDateString(),
                    // mantém EM_ORCAMENTO
                ]);

                $this->log(
                    chamadoId: $item->chamado_id,
                    itemId: $item->id,
                    statusDe: AssistenciaStatus::EM_ORCAMENTO->value,
                    statusPara: AssistenciaStatus::EM_ORCAMENTO->value,
                    mensagem: 'Orçamento reprovado',
                    meta: ['usuario_id' => $usuarioId, 'observacao' => $dto->observacao]
                );
            }

            return $item->fresh();
        });
    }

    /**
     * Registra retorno do item da assistência.
     * Movimenta: depósito assistência -> depósito retorno (quantidade 1). Status: RETORNADO.
     */
    public function registrarRetorno(RetornoDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()
                ->with('chamado')
                ->lockForUpdate()
                ->findOrFail($dto->itemId);

            if (!$item->deposito_assistencia_id) {
                throw new InvalidArgumentException('Depósito da assistência não definido no item.');
            }
            if (!$item->variacao_id) {
                throw new InvalidArgumentException('Variação obrigatória para movimentar estoque.');
            }
            if (empty($dto->depositoRetornoId)) {
                throw new InvalidArgumentException('Depósito de retorno obrigatório.');
            }

            // Movimentação de retorno
            $this->estoqueMovimentacaoService->registrarRetornoAssistencia(
                variacaoId: $item->variacao_id,
                depositoAssistenciaId: $item->deposito_assistencia_id,
                depositoRetornoId: $dto->depositoRetornoId,
                quantidade: 1,
                usuarioId: $usuarioId,
                observacao: "Retorno de assistência – Chamado #{$item->chamado->numero} / Item {$item->id}"
            );

            $item->update([
                'rastreio_retorno' => $dto->rastreioRetorno ? trim($dto->rastreioRetorno) : null,
                'data_retorno'     => $dto->dataRetorno ?: now()->toDateString(),
                'status_item'      => AssistenciaStatus::RETORNADO->value,
            ]);

            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $this->statusValue($item->status_item),
                statusPara: AssistenciaStatus::RETORNADO->value,
                mensagem: 'Item retornado da assistência',
                meta: ['usuario_id' => $usuarioId, 'rastreio' => $dto->rastreioRetorno]
            );

            // Atualiza status do chamado a partir do conjunto de itens
            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);

            return $item->fresh();
        });
    }

    /**
     * Atualiza o status do chamado com base no conjunto de itens.
     * - Se todos >= ENVIADO_ASSISTENCIA -> chamado ENVIADO_ASSISTENCIA
     * - Se todos == RETORNADO -> chamado FINALIZADO
     */
    public function atualizarStatusChamadoPorItens(int $chamadoId, ?int $usuarioId): void
    {
        $chamado = AssistenciaChamado::query()->with('itens')->findOrFail($chamadoId);
        $statuses = $chamado->itens
            ->pluck('status_item')
            ->map(fn ($s) => $this->statusValue($s))
            ->all();

        if (empty($statuses)) {
            return;
        }

        // Todos retornados -> FINALIZADO
        if (collect($statuses)->every(fn ($s) => $s === AssistenciaStatus::RETORNADO->value)) {
            $this->chamadoService->atualizarStatus($chamado, AssistenciaStatus::FINALIZADO, $usuarioId);
            return;
        }

        // Todos pelo menos ENVIADO_ASSISTENCIA -> ENVIADO_ASSISTENCIA
        if (collect($statuses)->every(function ($s) {
            $ordem = [
                AssistenciaStatus::ABERTO->value                 => 0,
                AssistenciaStatus::EM_ANALISE->value             => 1,
                AssistenciaStatus::ENVIADO_ASSISTENCIA->value    => 2,
                AssistenciaStatus::EM_ORCAMENTO->value           => 3,
                AssistenciaStatus::ORCAMENTO_APROVADO->value     => 4,
                AssistenciaStatus::EM_REPARO->value              => 5,
                AssistenciaStatus::SUBSTITUICAO_AUTORIZADA->value=> 6,
                AssistenciaStatus::DEVOLVIDO_FORNECEDOR->value   => 7,
                AssistenciaStatus::RETORNADO->value              => 8,
                AssistenciaStatus::FINALIZADO->value             => 9,
                AssistenciaStatus::CANCELADO->value              => 10,
            ];
            return ($ordem[$s] ?? -1) >= $ordem[AssistenciaStatus::ENVIADO_ASSISTENCIA->value];
        })) {
            $this->chamadoService->atualizarStatus($chamado, AssistenciaStatus::ENVIADO_ASSISTENCIA, $usuarioId);
            return;
        }

        // Caso contrário, mantém como está (ou implemente outras regras).
    }

    /** Helper de log */
    private function log(int $chamadoId, int $itemId, ?string $statusDe, ?string $statusPara, string $mensagem, array $meta = []): void
    {
        AssistenciaChamadoLog::query()->create([
            'chamado_id'  => $chamadoId,
            'item_id'     => $itemId,
            'status_de'   => $statusDe,
            'status_para' => $statusPara,
            'mensagem'    => $mensagem,
            'meta_json'   => $meta,
            'usuario_id'  => $meta['usuario_id'] ?? null,
        ]);
    }

    /**
     * Normaliza o status vindo do banco (enum/string) para string de enum.
     * @param mixed $status
     * @return string
     */
    private function statusValue(mixed $status): string
    {
        if ($status instanceof AssistenciaStatus) {
            return $status->value;
        }
        return (string) $status;
    }
}
