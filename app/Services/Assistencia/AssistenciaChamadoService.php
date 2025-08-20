<?php

namespace App\Services\Assistencia;

use App\DTOs\Assistencia\CriarChamadoDTO;
use App\DTOs\Assistencia\AtualizarChamadoDTO;
use App\Enums\AssistenciaStatus;
use App\Enums\PrioridadeChamado;
use App\Models\Assistencia;
use App\Models\AssistenciaChamado;
use App\Models\AssistenciaChamadoLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Serviço para abertura e manutenção de Chamados de Assistência.
 */
class AssistenciaChamadoService
{
    public function __construct(
        protected NumeroChamadoGenerator $numeroGenerator
    ) {}

    /**
     * Abre um chamado assistência.
     *
     * @param CriarChamadoDTO $dto
     * @param int|null $usuarioId
     * @return AssistenciaChamado
     */
    public function abrirChamado(CriarChamadoDTO $dto, ?int $usuarioId): AssistenciaChamado
    {
        if (!in_array($dto->origemTipo, ['pedido','consignacao','estoque'], true)) {
            throw new InvalidArgumentException('Origem inválida.');
        }

        return DB::transaction(function () use ($dto, $usuarioId) {
            $numero = $this->numeroGenerator->gerar();

            // SLA pelo prazo padrão da assistência (se informada)
            $sla = null;
            if ($dto->assistenciaId) {
                $assist = Assistencia::query()->find($dto->assistenciaId);
                if ($assist && $assist->prazo_padrao_dias) {
                    $sla = now()->addDays($assist->prazo_padrao_dias)->toDateString();
                }
            }

            $chamado = AssistenciaChamado::query()->create([
                'numero'         => $numero,
                'origem_tipo'    => $dto->origemTipo,
                'origem_id'      => $dto->origemId,
                'cliente_id'     => $dto->clienteId,
                'fornecedor_id'  => $dto->fornecedorId,
                'assistencia_id' => $dto->assistenciaId,
                'status'         => AssistenciaStatus::ABERTO,
                'prioridade'     => $dto->prioridade ?: PrioridadeChamado::MEDIA->value,
                'sla_data_limite'=> $sla,
                'canal_abertura' => $dto->canalAbertura,
                'observacoes'    => $dto->observacoes,
                'created_by'     => $usuarioId,
                'updated_by'     => $usuarioId,
            ]);

            $this->log(
                chamadoId: $chamado->id,
                statusDe: null,
                statusPara: AssistenciaStatus::ABERTO->value,
                mensagem: 'Chamado aberto',
                meta: ['usuario_id' => $usuarioId]
            );

            return $chamado->fresh(['assistencia']);
        });
    }

    /**
     * Atualiza campos básicos do chamado (sem alterar status).
     * Recalcula o SLA se a assistência for definida/alterada.
     *
     * @param AssistenciaChamado $chamado
     * @param AtualizarChamadoDTO $dto
     * @param int|null $usuarioId
     * @return AssistenciaChamado
     */
    public function atualizarChamado(AssistenciaChamado $chamado, AtualizarChamadoDTO $dto, ?int $usuarioId): AssistenciaChamado
    {
        return DB::transaction(function () use ($chamado, $dto, $usuarioId) {
            $antesAssistenciaId = $chamado->assistencia_id;

            $payload = $dto->toUpdateArray();
            if (!empty($payload)) {
                $chamado->fill($payload);
                $chamado->updated_by = $usuarioId;
                $chamado->save();
            }

            // Se mudou assistência, recalcula SLA
            if (array_key_exists('assistencia_id', $payload) && $chamado->assistencia_id !== $antesAssistenciaId) {
                $this->recalcularSLA($chamado);
            }

            return $chamado->fresh(['assistencia']);
        });
    }

    /**
     * Recalcula o SLA quando a assistência do chamado é definida/alterada.
     *
     * @param AssistenciaChamado $chamado
     * @return AssistenciaChamado
     */
    public function recalcularSLA(AssistenciaChamado $chamado): AssistenciaChamado
    {
        if (!$chamado->assistencia_id) {
            return $chamado;
        }

        $assist = Assistencia::query()->find($chamado->assistencia_id);
        if ($assist && $assist->prazo_padrao_dias) {
            $chamado->sla_data_limite = now()->addDays($assist->prazo_padrao_dias)->toDateString();
            $chamado->save();
        }

        return $chamado->fresh();
    }

    /**
     * Atualiza status do chamado.
     *
     * @param AssistenciaChamado $chamado
     * @param AssistenciaStatus $novoStatus
     * @param int|null $usuarioId
     * @return AssistenciaChamado
     */
    public function atualizarStatus(AssistenciaChamado $chamado, AssistenciaStatus $novoStatus, ?int $usuarioId): AssistenciaChamado
    {
        $antigo = $chamado->status;
        if ($antigo === $novoStatus) {
            return $chamado;
        }

        $chamado->status = $novoStatus;
        $chamado->updated_by = $usuarioId;
        $chamado->save();

        $this->log(
            chamadoId: $chamado->id,
            statusDe: $antigo?->value,
            statusPara: $novoStatus->value,
            mensagem: 'Status do chamado atualizado',
            meta: ['usuario_id' => $usuarioId]
        );

        return $chamado->fresh();
    }

    /**
     * Registra um log do chamado.
     *
     * @param int $chamadoId
     * @param string|null $statusDe
     * @param string|null $statusPara
     * @param string $mensagem
     * @param array<string, mixed> $meta
     * @return void
     */
    private function log(int $chamadoId, ?string $statusDe, ?string $statusPara, string $mensagem, array $meta = []): void
    {
        AssistenciaChamadoLog::query()->create([
            'chamado_id'  => $chamadoId,
            'item_id'     => null,
            'status_de'   => $statusDe,
            'status_para' => $statusPara,
            'mensagem'    => $mensagem,
            'meta_json'   => $meta,
            'usuario_id'  => $meta['usuario_id'] ?? null,
        ]);
    }
}
