<?php

namespace Database\Seeders;

use App\Enums\AssistenciaStatus;
use App\Enums\PrioridadeChamado;
use App\Models\Assistencia;
use App\Models\AssistenciaChamado;
use App\Models\AssistenciaChamadoItem;
use App\Models\AssistenciaDefeito;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cria um chamado de demonstração, SE houver dados mínimos:
 * - cliente em 'clientes'
 * - pelo menos 1 produto_variacoes
 * - ao menos 1 assistência e 1 defeito
 */
class AssistenciaDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Pré-condições de schema
        if (!Schema::hasTable('clientes') || !Schema::hasTable('produto_variacoes')) {
            return;
        }

        $clienteId = DB::table('clientes')->value('id');
        $variacao = DB::table('produto_variacoes')->first();
        $assistenciaId = Assistencia::query()->value('id');
        $defeitoId = AssistenciaDefeito::query()->value('id');

        if (!$clienteId || !$variacao || !$assistenciaId || !$defeitoId) {
            return; // dados mínimos indisponíveis, não cria demo
        }

        // Tenta obter um depósito origem e um depósito assistência (se existirem)
        $depositoOrigemId = Schema::hasTable('depositos') ? (DB::table('depositos')->value('id') ?? null) : null;
        $depositoAssistId = Schema::hasTable('depositos') ? (DB::table('depositos')->where('nome', 'ASSISTÊNCIA')->value('id') ?? null) : null;

        // Gera número simples – sua lógica real pode ser mais elaborada no Service
        $numero = 'ASS-' . date('Y') . '-' . str_pad((string) (DB::table('assistencia_chamados')->count() + 1), 5, '0', STR_PAD_LEFT);

        /** @var AssistenciaChamado $chamado */
        $chamado = AssistenciaChamado::query()->create([
            'numero' => $numero,
            'origem_tipo' => 'pedido', // demo
            'origem_id' => null,
            'cliente_id' => $clienteId,
            'fornecedor_id' => null,
            'assistencia_id' => $assistenciaId,
            'status' => AssistenciaStatus::ABERTO,
            'prioridade' => PrioridadeChamado::MEDIA,
            'sla_data_limite' => now()->addDays(30)->toDateString(),
            'canal_abertura' => 'loja',
            'observacoes' => 'Chamado de demonstração gerado pelo seeder.',
        ]);

        AssistenciaChamadoItem::query()->create([
            'chamado_id' => $chamado->id,
            'produto_id' => $variacao->produto_id ?? null,
            'variacao_id' => $variacao->id,
            'numero_serie' => null,
            'lote' => null,
            'defeito_id' => $defeitoId,
            'descricao_defeito_livre' => 'Cliente reporta rangido na estrutura.',
            'status_item' => AssistenciaStatus::EM_ANALISE,
            'pedido_id' => null,
            'pedido_item_id' => null,
            'consignacao_id' => null,
            'deposito_origem_id' => $depositoOrigemId,
            'assistencia_id' => $assistenciaId,
            'deposito_assistencia_id' => $depositoAssistId,
            'rastreio_envio' => null,
            'rastreio_retorno' => null,
            'data_envio' => null,
            'data_retorno' => null,
            'valor_orcado' => null,
            'aprovacao' => 'pendente',
            'data_aprovacao' => null,
            'observacoes' => 'Item adicionado pelo seeder de demo.',
        ]);
    }
}
