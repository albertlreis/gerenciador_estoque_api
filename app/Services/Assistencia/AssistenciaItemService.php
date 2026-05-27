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
use App\Models\PedidoItem;
use App\Services\AuditoriaLogService;
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
     * Adiciona um item ao chamado.
     * - Status inicial: ABERTO
     * - Infere produto_id da variação, se não informado
     */
    public function adicionarItem(AdicionarItemDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            $chamado = AssistenciaChamado::query()->findOrFail($dto->chamadoId);

            $variacaoId = $dto->variacaoId;
            if (!$variacaoId && $dto->pedidoItemId) {
                $variacaoId = (int) (PedidoItem::query()->where('id', $dto->pedidoItemId)->value('id_variacao'));
                if (!$variacaoId) {
                    throw new InvalidArgumentException('Não foi possível inferir a variação a partir do item do pedido.');
                }
            }

            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->create([
                'chamado_id'              => $chamado->id,
                'variacao_id'             => $variacaoId,
                'defeito_id'              => $dto->defeitoId,
                'status_item'             => AssistenciaStatus::ABERTO->value,
                'pedido_item_id'          => $dto->pedidoItemId,
                'consignacao_id'          => $dto->consignacaoId,
                'deposito_origem_id'      => $dto->depositoOrigemId,
                'observacoes'             => $dto->observacoes,
                'nota_numero'             => $dto->notaNumero,
                'prazo_finalizacao'       => $dto->prazoFinalizacao,
            ]);

            $this->log($chamado->id, $item->id, null, AssistenciaStatus::ABERTO->value, 'Item adicionado ao chamado', ['usuario_id' => $usuarioId]);

            // Ajuste de SLA do chamado (se tiver regra futura)
            // $this->chamadoService->recalcularSLA($chamado);

            return $item->fresh(['defeito','variacao.produto']);
        });
    }

    /**
     * Envia item para a assistência/fábrica.
     *
     * Regras:
     *  - Status permitido: ABERTO ou ENVIADO_FABRICA (reenvio)
     *  - Primeiro envio do chamado define a assistência (via payload assistencia_id)
     *  - Movimenta estoque: origem -> depósito destino (qtd 1)
     *
     * Parâmetros esperados no DTO:
     *  - item_id (int)
     *  - deposito_assistencia_id (int)
     *  - rastreio_envio (?string)
     *  - data_envio (?string, Y-m-d)
     *  - assistencia_id (?int)  // obrigatório apenas no 1º envio do chamado
     *
     * @param  EnviarItemAssistenciaDTO  $dto
     * @param  int|null                  $usuarioId
     * @return AssistenciaChamadoItem
     */
    public function enviarParaAssistencia(EnviarItemAssistenciaDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()
                ->with('chamado')
                ->lockForUpdate()
                ->findOrFail($dto->itemId);

            /** @var AssistenciaChamado $chamado */
            $chamado = AssistenciaChamado::query()
                ->lockForUpdate()
                ->findOrFail($item->chamado_id);

            // Bloqueios por status do chamado
            $statusChamado = $chamado->status?->value ?? (string) $chamado->status;
            if (in_array($statusChamado, [AssistenciaStatus::ENTREGUE->value, AssistenciaStatus::CANCELADO->value], true)) {
                throw new InvalidArgumentException('Chamado bloqueado para alterações.');
            }

            $local = (string)($chamado->local_reparo?->value ?? $chamado->local_reparo);
            if ($local !== 'fabrica') {
                throw new InvalidArgumentException('Este chamado não utiliza envio para assistência (local de reparo não é fábrica).');
            }

            // Regras de status do item
            $statusAtual = $item->status_item instanceof AssistenciaStatus
                ? $item->status_item->value
                : (string) $item->status_item;

            // Validações básicas
            if (!$item->variacao_id) {
                throw new InvalidArgumentException('Variação obrigatória para movimentar estoque.');
            }
            if (!$item->deposito_origem_id) {
                throw new InvalidArgumentException('Depósito de origem obrigatório.');
            }
            if (empty($dto->depositoAssistenciaId)) {
                throw new InvalidArgumentException('Depósito de destino obrigatório.');
            }

            // ✅ Definição/validação da assistência no envio (sem depender de depósitos)
            if (empty($chamado->assistencia_id)) {
                if (empty($dto->assistenciaId)) {
                    throw new InvalidArgumentException('Selecione a assistência no momento do envio.');
                }
                $chamado->assistencia_id = $dto->assistenciaId;
                $chamado->save();

            } else {
                if ($dto->assistenciaId && $dto->assistenciaId !== (int) $chamado->assistencia_id) {
                    throw new InvalidArgumentException('Todos os envios devem utilizar a mesma assistência do chamado.');
                }
            }

            // Movimentação de estoque (origem -> depósito destino)
            $this->estoqueMovimentacaoService->registrarEnvioAssistencia(
                variacaoId: $item->variacao_id,
                depositoOrigemId: $item->deposito_origem_id,
                depositoAssistenciaId: $dto->depositoAssistenciaId,
                quantidade: 1,
                usuarioId: $usuarioId,
                observacao: "Envio p/ assistência – Chamado #{$chamado->numero} / Item {$item->id}"
            );

            // ✅ Vincula o depósito de destino no item e atualiza status/datas/rastreio
            $item->update([
                'deposito_assistencia_id' => $dto->depositoAssistenciaId,
                'rastreio_envio'          => $dto->rastreioEnvio ? trim($dto->rastreioEnvio) : null,
                'data_envio'              => $dto->dataEnvio ?: now()->toDateString(),
                'status_item'             => AssistenciaStatus::ENVIADO_FABRICA->value,
            ]);

            // Log e atualização de status do chamado (com política de logs enxuta)
            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $statusAtual,
                statusPara: AssistenciaStatus::ENVIADO_FABRICA->value,
                mensagem: 'Item enviado para assistência',
                meta: ['usuario_id' => $usuarioId, 'rastreio' => $dto->rastreioEnvio]
            );

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);

            return $item->fresh();
        });
    }

    /**
     * Registra orçamento recebido da assistência.
     * - Define aprovação como PENDENTE
     * - Status do item: AGUARDANDO_REPARO (passo seguinte à chegada do orçamento)
     */
    public function registrarOrcamento(OrcamentoDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->lockForUpdate()->findOrFail($dto->itemId);

            if ($dto->valorOrcado <= 0) {
                throw new InvalidArgumentException('Valor de orçamento inválido.');
            }

            $statusAntes = $this->statusValue($item->status_item);

            $item->update([
                'valor_orcado' => $dto->valorOrcado,
                'aprovacao'    => AprovacaoOrcamento::PENDENTE->value,
                'status_item'  => AssistenciaStatus::AGUARDANDO_REPARO->value,
            ]);

            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $statusAntes,
                statusPara: AssistenciaStatus::AGUARDANDO_REPARO->value,
                mensagem: 'Orçamento registrado',
                meta: ['usuario_id' => $usuarioId, 'valor' => $dto->valorOrcado]
            );

            return $item->fresh();
        });
    }

    /**
     * Aprova/Reprova orçamento.
     * - Aprovado: mantém AGUARDANDO_REPARO (pronto para execução); grava aprovação/data.
     * - Reprovado: item CANCELADO.
     */
    public function decidirOrcamento(AprovacaoDTO $dto, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($dto, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->lockForUpdate()->findOrFail($dto->itemId);

            $statusAntes = AssistenciaStatus::AGUARDANDO_REPARO->value;

            if ($dto->aprovado) {
                $item->update([
                    'aprovacao'      => AprovacaoOrcamento::APROVADO->value,
                    'data_aprovacao' => now()->toDateString(),
                    // mantém AGUARDANDO_REPARO até avançar explicitamente (ex.: aguardando peça ou execução)
                    'status_item'    => AssistenciaStatus::AGUARDANDO_REPARO->value,
                ]);

                $this->log(
                    chamadoId: $item->chamado_id,
                    itemId: $item->id,
                    statusDe: $statusAntes,
                    statusPara: AssistenciaStatus::AGUARDANDO_REPARO->value,
                    mensagem: 'Orçamento aprovado',
                    meta: ['usuario_id' => $usuarioId, 'observacao' => $dto->observacao]
                );
            } else {
                $item->update([
                    'aprovacao'      => AprovacaoOrcamento::REPROVADO->value,
                    'data_aprovacao' => now()->toDateString(),
                    'status_item'    => AssistenciaStatus::CANCELADO->value,
                ]);

                $this->log(
                    chamadoId: $item->chamado_id,
                    itemId: $item->id,
                    statusDe: $statusAntes,
                    statusPara: AssistenciaStatus::CANCELADO->value,
                    mensagem: 'Orçamento reprovado',
                    meta: ['usuario_id' => $usuarioId, 'observacao' => $dto->observacao]
                );
            }

            // Atualiza status do chamado conforme conjunto
            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);

            return $item->fresh();
        });
    }

    /**
     * Registra retorno do item da assistência.
     * - Movimenta: depósito assistência -> depósito retorno (qtd 1)
     * - Status do item: REPARO_CONCLUIDO
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

            $statusAntes = $this->statusValue($item->status_item);

            $item->update([
                'rastreio_retorno' => $dto->rastreioRetorno ? trim($dto->rastreioRetorno) : null,
                'data_retorno'     => $dto->dataRetorno ?: now()->toDateString(),
                'status_item'      => AssistenciaStatus::REPARO_CONCLUIDO->value,
            ]);

            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $statusAntes,
                statusPara: AssistenciaStatus::REPARO_CONCLUIDO->value,
                mensagem: 'Item retornado da assistência',
                meta: ['usuario_id' => $usuarioId, 'rastreio' => $dto->rastreioRetorno]
            );

            // Atualiza status do chamado a partir do conjunto de itens
            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);

            return $item->fresh();
        });
    }

    /**
     * Coloca o item em 'aguardando_reparo' sem depender do orçamento (depósito/cliente).
     *
     * @param int $itemId
     * @param int|null $usuarioId
     * @param int|null $depositoEntradaId
     * @return AssistenciaChamadoItem
     */
    public function iniciarReparo(int $itemId, ?int $usuarioId, ?int $depositoEntradaId = null): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($itemId, $usuarioId, $depositoEntradaId) {
            $item = AssistenciaChamadoItem::query()->with('chamado')->lockForUpdate()->findOrFail($itemId);
            $chamado = AssistenciaChamado::query()->lockForUpdate()->findOrFail($item->chamado_id);

            $statusChamado = (string)($chamado->status?->value ?? $chamado->status);
            if (in_array($statusChamado, [AssistenciaStatus::ENTREGUE->value, AssistenciaStatus::CANCELADO->value], true)) {
                throw new InvalidArgumentException('Chamado bloqueado para alterações.');
            }

            $st = (string)($item->status_item instanceof AssistenciaStatus ? $item->status_item->value : $item->status_item);
            if (in_array($st, [AssistenciaStatus::REPARO_CONCLUIDO->value, AssistenciaStatus::ENTREGUE->value, AssistenciaStatus::CANCELADO->value], true)) {
                throw new InvalidArgumentException('Item não pode iniciar reparo neste estágio.');
            }

            $statusAntes = $st;

            // 👇 entrada de estoque se o local for DEPÓSITO
            $local = (string)($chamado->local_reparo?->value ?? $chamado->local_reparo);
            if ($local === 'deposito') {
                if (!$item->variacao_id) throw new InvalidArgumentException('Variação obrigatória.');
                if (empty($depositoEntradaId)) throw new InvalidArgumentException('Depósito de entrada obrigatório para reparo no depósito.');

                // mov. de entrada no depósito local (qtd 1)
                $this->estoqueMovimentacaoService->registrarEntradaDeposito(
                    variacaoId: $item->variacao_id,
                    depositoEntradaId: $depositoEntradaId,
                    quantidade: 1,
                    usuarioId: $usuarioId,
                    observacao: "Entrada para reparo local – Chamado #{$chamado->numero} / Item {$item->id}"
                );

                // guarda onde o item está no contexto do reparo local
                $item->deposito_assistencia_id = $depositoEntradaId;
            }

            $item->status_item = AssistenciaStatus::AGUARDANDO_REPARO->value;
            $item->save();

            $this->log($item->chamado_id, $item->id, $statusAntes, AssistenciaStatus::AGUARDANDO_REPARO->value, 'Reparo iniciado', [
                'usuario_id' => $usuarioId,
                'deposito_entrada_id' => $depositoEntradaId,
            ]);

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);
            return $item->fresh();
        });
    }

    /**
     * Conclui reparo local (depósito/cliente) sem movimentação de estoque.
     * Se o chamado estiver marcado para 'fabrica', deve-se usar o endpoint de retorno.
     *
     * @param  int         $itemId
     * @param  string|null $dataConclusao Y-m-d
     * @param  string|null $observacao
     * @param  int|null    $usuarioId
     * @return AssistenciaChamadoItem
     */
    public function concluirReparoLocal(int $itemId, ?string $dataConclusao, ?string $observacao, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($itemId, $dataConclusao, $observacao, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()
                ->with('chamado')
                ->lockForUpdate()
                ->findOrFail($itemId);

            /** @var AssistenciaChamado $chamado */
            $chamado = AssistenciaChamado::query()->lockForUpdate()->findOrFail($item->chamado_id);

            $statusChamado = (string)($chamado->status?->value ?? $chamado->status);
            if (in_array($statusChamado, [AssistenciaStatus::ENTREGUE->value, AssistenciaStatus::CANCELADO->value], true)) {
                throw new InvalidArgumentException('Chamado bloqueado para alterações.');
            }

            // apenas quando o local de reparo não for a fábrica
            $local = (string)($chamado->local_reparo?->value ?? $chamado->local_reparo);
            if ($local === 'fabrica') {
                throw new InvalidArgumentException('Para reparo em fábrica, utilize o registro de retorno.');
            }

            $st = (string)($item->status_item instanceof AssistenciaStatus ? $item->status_item->value : $item->status_item);
            if ($st !== AssistenciaStatus::AGUARDANDO_REPARO->value) {
                throw new InvalidArgumentException('Item precisa estar em "aguardando_reparo" para concluir o reparo.');
            }

            $statusAntes = $st;

            // sem movimentação; por compatibilidade usamos data_retorno como data de conclusão
            $item->update([
                'data_retorno' => $dataConclusao ?: now()->toDateString(),
                'status_item'  => AssistenciaStatus::REPARO_CONCLUIDO->value,
            ]);

            $this->log(
                chamadoId: $item->chamado_id,
                itemId: $item->id,
                statusDe: $statusAntes,
                statusPara: AssistenciaStatus::REPARO_CONCLUIDO->value,
                mensagem: 'Reparo concluído (depósito/cliente) – sem movimentação',
                meta: ['usuario_id' => $usuarioId, 'observacao' => $observacao]
            );

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);

            return $item->fresh();
        });
    }

    /**
     * Marca o item como "aguardando_resposta_fabrica".
     *
     * Regras:
     * - Status permitido: ABERTO ou ENVIADO_FABRICA
     * - Atualiza agregação do chamado ao final.
     *
     * @param  int      $itemId
     * @param  int|null $usuarioId
     * @return AssistenciaChamadoItem
     */
    public function marcarAguardandoRespostaFabrica(int $itemId, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($itemId, $usuarioId) {
            /** @var AssistenciaChamadoItem $item */
            $item = AssistenciaChamadoItem::query()->with('chamado')->lockForUpdate()->findOrFail($itemId);
            /** @var AssistenciaChamado $chamado */
            $chamado = AssistenciaChamado::query()->lockForUpdate()->findOrFail($item->chamado_id);

            $st = (string)($item->status_item instanceof AssistenciaStatus ? $item->status_item->value : $item->status_item);
            if (!in_array($st, [
                AssistenciaStatus::ABERTO->value,
                AssistenciaStatus::ENVIADO_FABRICA->value,
            ], true)) {
                throw new InvalidArgumentException('Ação permitida somente a partir de "aberto" ou "enviado_fabrica".');
            }

            $item->update([
                'status_item' => AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value,
            ]);

            $this->log(
                $item->chamado_id,
                $item->id,
                $st,
                AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value,
                'Aguardando resposta da fábrica',
                ['usuario_id' => $usuarioId]
            );

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);
            return $item->fresh();
        });
    }

    public function marcarAguardandoPeca(int $itemId, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($itemId, $usuarioId) {
            $item = AssistenciaChamadoItem::query()->with('chamado')->lockForUpdate()->findOrFail($itemId);

            $st = (string)($item->status_item instanceof AssistenciaStatus ? $item->status_item->value : $item->status_item);
            if (!in_array($st, [AssistenciaStatus::AGUARDANDO_REPARO->value], true)) {
                throw new InvalidArgumentException('Ação permitida após orçamento aprovado (aguardando_reparo).');
            }

            $item->update(['status_item' => AssistenciaStatus::AGUARDANDO_PECA->value]);

            $this->log($item->chamado_id, $item->id, $st, AssistenciaStatus::AGUARDANDO_PECA->value, 'Aguardando peça', [
                'usuario_id' => $usuarioId,
            ]);

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);
            return $item->fresh();
        });
    }


    public function registrarSaidaDaFabrica(int $itemId, ?string $rastreio, ?string $dataEnvio, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($itemId, $rastreio, $dataEnvio, $usuarioId) {
            $item = AssistenciaChamadoItem::query()->with('chamado')->lockForUpdate()->findOrFail($itemId);

            $st = (string)($item->status_item instanceof AssistenciaStatus ? $item->status_item->value : $item->status_item);
            if (!in_array($st, [
                AssistenciaStatus::ENVIADO_FABRICA->value,
                AssistenciaStatus::AGUARDANDO_REPARO->value,
                AssistenciaStatus::AGUARDANDO_PECA->value,
                AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value,
            ], true)) {
                throw new InvalidArgumentException('O item não está em um estágio válido para saída da fábrica.');
            }

            $item->update([
                'status_item'      => AssistenciaStatus::EM_TRANSITO_RETORNO->value,
                'rastreio_retorno' => $rastreio ? trim($rastreio) : null,
            ]);

            $this->log($item->chamado_id, $item->id, $st, AssistenciaStatus::EM_TRANSITO_RETORNO->value, 'Saída da fábrica (em trânsito)', [
                'usuario_id' => $usuarioId,
                'data_envio_retorno' => $dataEnvio,
                'rastreio' => $rastreio,
            ]);

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);
            return $item->fresh();
        });
    }

    public function registrarEntregaAoCliente(int $itemId, int $depositoSaidaId, ?string $dataEntrega, ?string $observacao, ?int $usuarioId): AssistenciaChamadoItem
    {
        return DB::transaction(function () use ($itemId, $depositoSaidaId, $dataEntrega, $observacao, $usuarioId) {
            $item = AssistenciaChamadoItem::query()->with('chamado')->lockForUpdate()->findOrFail($itemId);

            if (!$item->variacao_id) throw new InvalidArgumentException('Variação obrigatória.');
            if ($depositoSaidaId <= 0) throw new InvalidArgumentException('Depósito de saída inválido.');

            $st = (string)($item->status_item instanceof AssistenciaStatus ? $item->status_item->value : $item->status_item);
            if ($st !== AssistenciaStatus::REPARO_CONCLUIDO->value) {
                throw new InvalidArgumentException('Somente itens com reparo concluído podem ser entregues.');
            }

            // baixa do estoque (depósito -> cliente)
            $this->estoqueMovimentacaoService->registrarSaidaEntregaCliente(
                variacaoId: $item->variacao_id,
                depositoSaidaId: $depositoSaidaId,
                quantidade: 1,
                usuarioId: $usuarioId,
                observacao: "Entrega ao cliente – Chamado #{$item->chamado->numero} / Item {$item->id}"
            );

            $item->update([
                'status_item' => AssistenciaStatus::ENTREGUE->value,
            ]);

            $this->log($item->chamado_id, $item->id, $st, AssistenciaStatus::ENTREGUE->value, 'Item entregue ao cliente', [
                'usuario_id' => $usuarioId,
                'deposito_saida_id' => $depositoSaidaId,
                'data_entrega' => $dataEntrega,
                'observacao' => $observacao,
            ]);

            $this->atualizarStatusChamadoPorItens($item->chamado_id, $usuarioId);
            return $item->fresh();
        });
    }

    /**
     * Atualiza o status do chamado com base no conjunto dos itens (ativos).
     *
     * PRIORIDADE (da mais alta para a mais baixa):
     *  1) Todos ENTREGUE            -> Chamado ENTREGUE (loga)
     *  2) Todos REPARO_CONCLUIDO    -> Chamado REPARO_CONCLUIDO (loga)
     *  3) Qualquer AGUARDANDO_RESPOSTA_FABRICA -> Chamado AGUARDANDO_RESPOSTA_FABRICA (sem log)
     *  4) Qualquer AGUARDANDO_PECA  -> Chamado AGUARDANDO_PECA (sem log)
     *  5) Qualquer AGUARDANDO_REPARO-> Chamado AGUARDANDO_REPARO (sem log)
     *  6) Qualquer EM_TRANSITO_RETORNO -> Chamado EM_TRANSITO_RETORNO (sem log)
     *  7) Qualquer ENVIADO_FABRICA  -> Chamado ENVIADO_FABRICA (sem log)
     *  8) Caso contrário            -> Chamado ABERTO (sem log)
     *
     * Notas:
     * - Itens CANCELADOS são ignorados na avaliação (não contaminam o status do chamado).
     * - "Sem log" aqui significa que a mudança do chamado é colateral, já devidamente
     *   registrada nos logs de itens. Evita poluição de logs de chamado.
     *
     * @param  int      $chamadoId
     * @param  int|null $usuarioId
     * @return void
     */
    public function atualizarStatusChamadoPorItens(int $chamadoId, ?int $usuarioId): void
    {
        /** @var AssistenciaChamado $chamado */
        $chamado = AssistenciaChamado::query()->with('itens')->findOrFail($chamadoId);

        // Considera apenas itens "ativos" (não cancelados) para o estado de trabalho
        $ativos = $chamado->itens
            ->filter(function ($i) {
                $sv = $this->statusValue($i->status_item);
                return $sv !== AssistenciaStatus::CANCELADO->value;
            })
            ->values();

        if ($ativos->isEmpty()) {
            // Se não há itens ativos, não força alteração aqui.
            return;
        }

        $statuses = $ativos->pluck('status_item')->map(fn ($s) => $this->statusValue($s))->all();

        $todos = function (string $status) use ($statuses) {
            return collect($statuses)->every(fn ($s) => $s === $status);
        };

        $tem = function (string $status) use ($statuses) {
            return in_array($status, $statuses, true);
        };

        // 1) Todos ENTREGUE -> chamado ENTREGUE (com log)
        if ($todos(AssistenciaStatus::ENTREGUE->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::ENTREGUE, $usuarioId, true);
            return;
        }

        // 2) Todos REPARO_CONCLUIDO -> chamado REPARO_CONCLUIDO (com log)
        if ($todos(AssistenciaStatus::REPARO_CONCLUIDO->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::REPARO_CONCLUIDO, $usuarioId, true);
            return;
        }

        // 3) Qualquer aguardando_resposta_fabrica -> chamado aguardando_resposta_fabrica
        if ($tem(AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA, $usuarioId, false);
            return;
        }

        // 4) Qualquer aguardando_peca -> chamado aguardando_peca
        if ($tem(AssistenciaStatus::AGUARDANDO_PECA->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::AGUARDANDO_PECA, $usuarioId, false);
            return;
        }

        // 5) Qualquer aguardando_reparo -> chamado aguardando_reparo
        if ($tem(AssistenciaStatus::AGUARDANDO_REPARO->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::AGUARDANDO_REPARO, $usuarioId, false);
            return;
        }

        // 6) Qualquer em_transito_retorno -> chamado em_transito_retorno
        if ($tem(AssistenciaStatus::EM_TRANSITO_RETORNO->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::EM_TRANSITO_RETORNO, $usuarioId, false);
            return;
        }

        // 7) Qualquer enviado_fabrica -> chamado enviado_fabrica
        if ($tem(AssistenciaStatus::ENVIADO_FABRICA->value)) {
            app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::ENVIADO_FABRICA, $usuarioId, false);
            return;
        }

        // 8) Caso não encaixe em nenhuma das anteriores, assume "aberto"
        app(AssistenciaChamadoService::class)->atualizarStatus($chamado, AssistenciaStatus::ABERTO, $usuarioId, false);
    }

    /** Helper de log */
    private function log(int $chamadoId, int $itemId, ?string $statusDe, ?string $statusPara, string $mensagem, array $meta = []): void
    {
        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'evento',
            'categoria' => 'negocio',
            'modulo' => 'assistencias',
            'acao' => 'status',
            'status' => $statusPara,
            'label' => 'Log de assistencia',
            'message' => $mensagem,
            'actor_id' => $meta['usuario_id'] ?? null,
            'entity_type' => AssistenciaChamado::class,
            'entity_id' => $chamadoId,
            'context_json' => [
                'item_id' => $itemId,
                'status_de' => $statusDe,
                'status_para' => $statusPara,
                'meta' => $meta,
            ],
            'source_system' => 'estoque',
            'source_kind' => 'business_event',
            'retention_days' => 365,
        ], [[
            'campo' => 'status',
            'old' => $statusDe,
            'new' => $statusPara,
            'value_type' => 'string',
        ]]);
    }

    /**
     * Normaliza o status vindo do banco (enum|string) para string de enum.
     */
    private function statusValue(mixed $status): string
    {
        if ($status instanceof AssistenciaStatus) {
            return $status->value;
        }
        return (string) $status;
    }
}
