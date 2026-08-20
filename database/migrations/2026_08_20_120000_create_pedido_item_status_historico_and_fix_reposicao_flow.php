<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FLUXO_REPOSICAO_LEGADO = [
        'pedido_criado',
        'entrega_estoque',
        'envio_cliente',
        'entrega_cliente',
        'finalizado',
    ];

    private const FLUXO_REPOSICAO_CORRIGIDO = [
        'pedido_criado',
        'pedido_enviado_fabrica',
        'nota_emitida',
        'previsao_embarque_fabrica',
        'embarque_fabrica',
        'nota_recebida_compra',
        'previsao_entrega_estoque',
        'entrega_estoque',
        'finalizado',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pedido_item_status_historico')) {
            Schema::create('pedido_item_status_historico', function (Blueprint $table) {
                $table->id();
                $table->uuid('grupo_uuid')->index();
                $table->unsignedInteger('pedido_id');
                $table->unsignedInteger('pedido_item_id');
                $table->string('status', 50)->index();
                $table->unsignedInteger('quantidade');
                $table->unsignedInteger('quantidade_avancada');
                $table->timestamp('data_status');
                $table->date('data_prevista')->nullable();
                $table->foreignId('usuario_id')->nullable();
                $table->text('observacoes')->nullable();
                $table->timestamps();

                $table->index(['pedido_id', 'status'], 'pish_pedido_status_idx');
                $table->index(['pedido_item_id', 'status'], 'pish_item_status_idx');
                $table->unique(['grupo_uuid', 'pedido_item_id'], 'pish_grupo_item_unique');
                $table->foreign('pedido_id')->references('id')->on('pedidos')->cascadeOnDelete();
                $table->foreign('pedido_item_id')->references('id')->on('pedido_itens')->cascadeOnDelete();
                $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();
            });
        }

        $this->substituirFluxoSeIgual(self::FLUXO_REPOSICAO_LEGADO, self::FLUXO_REPOSICAO_CORRIGIDO);
    }

    public function down(): void
    {
        $this->substituirFluxoSeIgual(self::FLUXO_REPOSICAO_CORRIGIDO, self::FLUXO_REPOSICAO_LEGADO);
        Schema::dropIfExists('pedido_item_status_historico');
    }

    private function substituirFluxoSeIgual(array $esperado, array $novo): void
    {
        if (! Schema::hasTable('pedido_status_fluxo_itens') || ! Schema::hasTable('pedido_statuses')) {
            return;
        }

        $atual = DB::table('pedido_status_fluxo_itens as fluxo')
            ->join('pedido_statuses as status', 'status.id', '=', 'fluxo.pedido_status_id')
            ->where('fluxo.tipo_fluxo', 'reposicao')
            ->orderBy('fluxo.ordem')
            ->pluck('status.codigo')
            ->all();

        if ($atual !== $esperado) {
            return;
        }

        DB::transaction(function () use ($novo) {
            $ids = DB::table('pedido_statuses')->whereIn('codigo', $novo)->pluck('id', 'codigo');
            if ($ids->count() !== count($novo)) {
                return;
            }

            DB::table('pedido_status_fluxo_itens')->where('tipo_fluxo', 'reposicao')->delete();
            $agora = now();

            foreach ($novo as $indice => $codigo) {
                DB::table('pedido_status_fluxo_itens')->insert([
                    'tipo_fluxo' => 'reposicao',
                    'pedido_status_id' => $ids[$codigo],
                    'ordem' => $indice + 1,
                    'prazo_dias' => $this->prazoDias($codigo),
                    'exige_previsao_manual' => in_array($codigo, [
                        'previsao_embarque_fabrica',
                        'embarque_fabrica',
                        'previsao_entrega_estoque',
                        'finalizado',
                    ], true),
                    'ativo' => true,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        });
    }

    private function prazoDias(string $codigo): ?int
    {
        return match ($codigo) {
            'pedido_enviado_fabrica' => 5,
            'previsao_embarque_fabrica' => 7,
            'previsao_entrega_estoque' => 7,
            default => null,
        };
    }
};
