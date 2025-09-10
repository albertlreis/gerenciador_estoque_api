<?php

namespace Database\Seeders;

use App\Enums\AssistenciaStatus;
use App\Enums\PrioridadeChamado;
use App\Models\Assistencia;
use App\Models\AssistenciaChamado;
use App\Models\AssistenciaChamadoItem;
use App\Models\AssistenciaChamadoLog;
use App\Models\AssistenciaDefeito;
use App\Models\Pedido;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistenciaDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('pedidos') || !Schema::hasTable('pedido_itens') || !Schema::hasTable('produto_variacoes')) {
            return;
        }

        $assistenciaId = Assistencia::query()->inRandomOrder()->value('id');
        $defeitoIds    = AssistenciaDefeito::query()->pluck('id')->all();

        if (!$assistenciaId || empty($defeitoIds)) {
            return;
        }

        // pega 10 pedidos aleatórios que tenham itens
        $pedidos = Pedido::query()
            ->with(['itens'])
            ->whereHas('itens')
            ->inRandomOrder()
            ->limit(10)
            ->get();

        if ($pedidos->isEmpty()) {
            return;
        }

        $fluxo = [
            AssistenciaStatus::ABERTO->value,
            AssistenciaStatus::ENVIADO_FABRICA->value,
            AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value,
            AssistenciaStatus::AGUARDANDO_PECA->value,
            AssistenciaStatus::AGUARDANDO_REPARO->value,
            AssistenciaStatus::REPARO_CONCLUIDO->value,
            AssistenciaStatus::ENTREGUE->value,
        ];

        $finais = [
            AssistenciaStatus::ABERTO->value,
            AssistenciaStatus::ENVIADO_FABRICA->value,
            AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value,
            AssistenciaStatus::AGUARDANDO_PECA->value,
            AssistenciaStatus::AGUARDANDO_REPARO->value,
            AssistenciaStatus::REPARO_CONCLUIDO->value,
            AssistenciaStatus::ENTREGUE->value,
            AssistenciaStatus::CANCELADO->value,
            AssistenciaStatus::AGUARDANDO_REPARO->value,
            AssistenciaStatus::ENVIADO_FABRICA->value,
        ];

        $inicio = (int) DB::table('assistencia_chamados')->count();

        foreach ($pedidos as $idx => $pedido) {
            if (!isset($finais[$idx])) break;
            $statusFinal = $finais[$idx];

            $seq    = $inicio + $idx + 1;
            $numero = 'ASS-' . date('Y') . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);

            $localReparoOpts = ['deposito','fabrica','cliente'];
            $custoRespOpts   = ['cliente','loja'];

            $chamado = AssistenciaChamado::query()->create([
                'numero'            => $numero,
                'origem_tipo'       => 'pedido',
                'origem_id'         => $pedido->id,        // opcional para histórico
                'pedido_id'         => $pedido->id,        // vínculo oficial
                'assistencia_id'    => $assistenciaId,
                'status'            => $statusFinal,
                'prioridade'        => PrioridadeChamado::MEDIA->value,
                'sla_data_limite'   => now()->addDays(30)->toDateString(),
                'observacoes'       => 'Chamado de demonstração (seed).',
                'local_reparo'      => $localReparoOpts[$seq % count($localReparoOpts)],
                'custo_responsavel' => $custoRespOpts[$seq % count($custoRespOpts)],
            ]);

            // escolhe 1–2 itens aleatórios do pedido
            $itensPedido = $pedido->itens->shuffle()->take(rand(1, min(2, $pedido->itens->count())));

            $path = $statusFinal === AssistenciaStatus::CANCELADO->value
                ? [AssistenciaStatus::ABERTO->value, AssistenciaStatus::CANCELADO->value]
                : array_slice($fluxo, 0, array_search($statusFinal, $fluxo, true) + 1);

            $temEnvio     = in_array(AssistenciaStatus::ENVIADO_FABRICA->value, $path, true);
            $temRetorno   = in_array(AssistenciaStatus::REPARO_CONCLUIDO->value, $path, true);
            $temOrcamento = in_array(AssistenciaStatus::AGUARDANDO_REPARO->value, $path, true);
            $foiEntregue  = end($path) === AssistenciaStatus::ENTREGUE->value;

            $dataCriacao      = now()->subDays(14 - $idx);
            $dataEnvio        = $temEnvio   ? $dataCriacao->copy()->addDays(2)->toDateString() : null;
            $dataRetorno      = $temRetorno ? $dataCriacao->copy()->addDays(10)->toDateString() : null;
            $prazoFinalizacao = $dataCriacao->copy()->addDays(20)->toDateString();

            foreach ($itensPedido as $pi) {
                // variacao vem do pedido_item
                $variacaoId = $pi->id_variacao ?? null;

                $item = AssistenciaChamadoItem::query()->create([
                    'chamado_id'              => $chamado->id,
                    'variacao_id'             => $variacaoId,
                    'pedido_item_id'          => $pi->id,
                    'defeito_id'              => collect($defeitoIds)->random(),
                    'status_item'             => $statusFinal,
                    'consignacao_id'          => null,
                    'deposito_origem_id'      => null,
                    'deposito_assistencia_id' => null,
                    'rastreio_envio'          => $temEnvio ? 'ENV' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) : null,
                    'rastreio_retorno'        => $temRetorno ? 'RET' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) : null,
                    'data_envio'              => $dataEnvio,
                    'data_retorno'            => $dataRetorno,
                    'valor_orcado'            => $temOrcamento ? 250.00 + $seq : null,
                    'aprovacao'               => $temOrcamento ? 'aprovado' : 'pendente',
                    'data_aprovacao'          => $temOrcamento ? $dataCriacao->copy()->addDays(7)->toDateString() : null,
                    'observacoes'             => 'Item de demonstração (seed).',
                    'nota_numero'             => 'NF-' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT),
                    'prazo_finalizacao'       => $prazoFinalizacao,
                ]);

                // Logs por item (principais marcos)
                $this->logItem($chamado->id, $item->id, null, AssistenciaStatus::ABERTO->value, 'Item adicionado ao chamado', $dataCriacao);

                if ($temEnvio) {
                    $this->logItem($chamado->id, $item->id, AssistenciaStatus::ABERTO->value, AssistenciaStatus::ENVIADO_FABRICA->value, 'Item enviado para assistência', $dataCriacao->copy()->addDays(2), ['rastreio' => $item->rastreio_envio]);
                }
                if ($temOrcamento) {
                    $this->logItem($chamado->id, $item->id, AssistenciaStatus::ENVIADO_FABRICA->value, AssistenciaStatus::AGUARDANDO_REPARO->value, 'Orçamento aprovado (aguardando execução do reparo)', $dataCriacao->copy()->addDays(7), ['valor' => $item->valor_orcado]);
                }
                if ($temRetorno) {
                    $this->logItem($chamado->id, $item->id, AssistenciaStatus::AGUARDANDO_REPARO->value, AssistenciaStatus::REPARO_CONCLUIDO->value, 'Item retornado da assistência (reparo concluído)', $dataCriacao->copy()->addDays(10), ['rastreio' => $item->rastreio_retorno]);
                }
                if ($foiEntregue) {
                    $this->logItem($chamado->id, $item->id, AssistenciaStatus::REPARO_CONCLUIDO->value, AssistenciaStatus::ENTREGUE->value, 'Item entregue ao cliente', $dataCriacao->copy()->addDays(12));
                }
            }

            // Logs do chamado (ordem do fluxo)
            $this->logChamado($chamado->id, null, AssistenciaStatus::ABERTO->value, 'Chamado aberto', $dataCriacao);
            $prev = AssistenciaStatus::ABERTO->value;
            foreach (array_slice($path, 1) as $next) {
                $mensagem = match ($next) {
                    AssistenciaStatus::ENVIADO_FABRICA->value             => 'Chamado enviado para a fábrica',
                    AssistenciaStatus::AGUARDANDO_RESPOSTA_FABRICA->value => 'Aguardando resposta da fábrica',
                    AssistenciaStatus::AGUARDANDO_PECA->value             => 'Aguardando peça',
                    AssistenciaStatus::AGUARDANDO_REPARO->value           => 'Aguardando reparo',
                    AssistenciaStatus::REPARO_CONCLUIDO->value            => 'Reparo concluído',
                    AssistenciaStatus::ENTREGUE->value                    => 'Chamado entregue ao cliente',
                    AssistenciaStatus::CANCELADO->value                   => 'Chamado cancelado',
                    default                                                => 'Status do chamado atualizado',
                };

                $dataLog = $dataCriacao->copy()->addDays(array_search($next, $fluxo, true) ?: 1);
                $this->logChamado($chamado->id, $prev, $next, $mensagem, $dataLog);
                $prev = $next;
            }
        }
    }

    private function logChamado(int $chamadoId, ?string $de, ?string $para, string $msg, $data, array $meta = []): void
    {
        AssistenciaChamadoLog::query()->create([
            'chamado_id'  => $chamadoId,
            'item_id'     => null,
            'status_de'   => $de,
            'status_para' => $para,
            'mensagem'    => $msg,
            'meta_json'   => $meta ?: ['usuario_id' => null],
            'usuario_id'  => null,
            'created_at'  => $data,
            'updated_at'  => $data,
        ]);
    }

    private function logItem(int $chamadoId, int $itemId, ?string $de, ?string $para, string $msg, $data, array $meta = []): void
    {
        AssistenciaChamadoLog::query()->create([
            'chamado_id'  => $chamadoId,
            'item_id'     => $itemId,
            'status_de'   => $de,
            'status_para' => $para,
            'mensagem'    => $msg,
            'meta_json'   => $meta ?: ['usuario_id' => null],
            'usuario_id'  => null,
            'created_at'  => $data,
            'updated_at'  => $data,
        ]);
    }
}
