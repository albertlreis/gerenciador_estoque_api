<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pedido_statuses')) {
            Schema::create('pedido_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 50)->unique();
                $table->string('nome', 120);
                $table->text('descricao')->nullable();
                $table->string('cor', 20)->default('#adb5bd');
                $table->string('severidade', 30)->default('secondary');
                $table->string('icone', 80)->default('pi pi-info-circle');
                $table->boolean('ativo')->default(true);
                $table->boolean('sistema')->default(false);
                $table->boolean('protegido')->default(false);
                $table->string('papel_operacional', 80)->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pedido_status_fluxo_itens')) {
            Schema::create('pedido_status_fluxo_itens', function (Blueprint $table) {
                $table->id();
                $table->string('tipo_fluxo', 30)->index();
                $table->foreignId('pedido_status_id')->constrained('pedido_statuses')->cascadeOnDelete();
                $table->unsignedInteger('ordem');
                $table->integer('prazo_dias')->nullable();
                $table->boolean('exige_previsao_manual')->default(false);
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->unique(['tipo_fluxo', 'pedido_status_id'], 'psfi_tipo_status_unique');
                $table->unique(['tipo_fluxo', 'ordem'], 'psfi_tipo_ordem_unique');
            });
        }

        $this->seedCatalogo();
        $this->seedFluxos();
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_status_fluxo_itens');
        Schema::dropIfExists('pedido_statuses');
    }

    private function seedCatalogo(): void
    {
        $now = now();

        foreach ($this->statusLegados() as $status) {
            DB::table('pedido_statuses')->updateOrInsert(
                ['codigo' => $status['codigo']],
                [
                    'nome' => $status['nome'],
                    'descricao' => $status['descricao'] ?? null,
                    'cor' => $status['cor'],
                    'severidade' => $status['severidade'],
                    'icone' => $status['icone'],
                    'ativo' => true,
                    'sistema' => true,
                    'protegido' => $status['protegido'],
                    'papel_operacional' => $status['papel_operacional'] ?? $status['codigo'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function seedFluxos(): void
    {
        $now = now();
        $ids = DB::table('pedido_statuses')->pluck('id', 'codigo');

        foreach ($this->fluxosLegados() as $tipo => $itens) {
            foreach ($itens as $ordem => $item) {
                $statusId = $ids[$item['codigo']] ?? null;

                if (!$statusId) {
                    continue;
                }

                DB::table('pedido_status_fluxo_itens')->updateOrInsert(
                    [
                        'tipo_fluxo' => $tipo,
                        'pedido_status_id' => $statusId,
                    ],
                    [
                        'ordem' => $ordem + 1,
                        'prazo_dias' => $item['prazo_dias'] ?? null,
                        'exige_previsao_manual' => (bool) ($item['exige_previsao_manual'] ?? false),
                        'ativo' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    private function statusLegados(): array
    {
        return [
            ['codigo' => 'pedido_criado', 'nome' => 'Pedido Criado', 'cor' => '#007bff', 'severidade' => 'secondary', 'icone' => 'pi pi-file', 'protegido' => true],
            ['codigo' => 'pedido_enviado_fabrica', 'nome' => 'Enviado a Fabrica', 'cor' => '#0dcaf0', 'severidade' => 'info', 'icone' => 'pi pi-send', 'protegido' => false],
            ['codigo' => 'nota_emitida', 'nome' => 'Nota Emitida', 'cor' => '#20c997', 'severidade' => 'success', 'icone' => 'pi pi-file-edit', 'protegido' => false],
            ['codigo' => 'previsao_embarque_fabrica', 'nome' => 'Previsao de Embarque', 'cor' => '#ffc107', 'severidade' => 'warning', 'icone' => 'pi pi-calendar-clock', 'protegido' => false],
            ['codigo' => 'embarque_fabrica', 'nome' => 'Embarque da Fabrica', 'cor' => '#17a2b8', 'severidade' => 'info', 'icone' => 'pi pi-truck', 'protegido' => false],
            ['codigo' => 'nota_recebida_compra', 'nome' => 'Nota Recebida (Compra)', 'cor' => '#6610f2', 'severidade' => 'success', 'icone' => 'pi pi-download', 'protegido' => false],
            ['codigo' => 'previsao_entrega_estoque', 'nome' => 'Previsao de Entrega ao Estoque', 'cor' => '#ffc107', 'severidade' => 'warning', 'icone' => 'pi pi-calendar-clock', 'protegido' => false],
            ['codigo' => 'entrega_estoque', 'nome' => 'Entrega ao Estoque', 'cor' => '#6f42c1', 'severidade' => 'success', 'icone' => 'pi pi-box', 'protegido' => true],
            ['codigo' => 'previsao_envio_cliente', 'nome' => 'Previsao de Envio ao Cliente', 'cor' => '#ffc107', 'severidade' => 'warning', 'icone' => 'pi pi-calendar-minus', 'protegido' => false],
            ['codigo' => 'envio_cliente', 'nome' => 'Envio ao Cliente', 'cor' => '#fd7e14', 'severidade' => 'warning', 'icone' => 'pi pi-send', 'protegido' => true],
            ['codigo' => 'entrega_cliente', 'nome' => 'Entrega ao Cliente', 'cor' => '#198754', 'severidade' => 'success', 'icone' => 'pi pi-home', 'protegido' => true],
            ['codigo' => 'consignado', 'nome' => 'Consignado', 'cor' => '#0dcaf0', 'severidade' => 'info', 'icone' => 'pi pi-briefcase', 'protegido' => true],
            ['codigo' => 'devolucao_consignacao', 'nome' => 'Devolucao de Consignacao', 'cor' => '#dc3545', 'severidade' => 'danger', 'icone' => 'pi pi-undo', 'protegido' => true],
            ['codigo' => 'finalizado', 'nome' => 'Finalizado', 'cor' => '#198754', 'severidade' => 'success', 'icone' => 'pi pi-check-circle', 'protegido' => true],
            ['codigo' => 'cancelado', 'nome' => 'Cancelado', 'cor' => '#dc3545', 'severidade' => 'danger', 'icone' => 'pi pi-times-circle', 'protegido' => true],
        ];
    }

    private function fluxosLegados(): array
    {
        return [
            'venda' => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'pedido_enviado_fabrica', 'prazo_dias' => 5],
                ['codigo' => 'nota_emitida'],
                ['codigo' => 'previsao_embarque_fabrica', 'prazo_dias' => 7, 'exige_previsao_manual' => true],
                ['codigo' => 'embarque_fabrica', 'exige_previsao_manual' => true],
                ['codigo' => 'nota_recebida_compra'],
                ['codigo' => 'previsao_entrega_estoque', 'prazo_dias' => 7, 'exige_previsao_manual' => true],
                ['codigo' => 'entrega_estoque', 'exige_previsao_manual' => true],
                ['codigo' => 'previsao_envio_cliente', 'prazo_dias' => 3],
                ['codigo' => 'envio_cliente'],
                ['codigo' => 'entrega_cliente', 'prazo_dias' => 3],
                ['codigo' => 'finalizado', 'exige_previsao_manual' => true],
            ],
            'reposicao' => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'entrega_estoque'],
                ['codigo' => 'envio_cliente'],
                ['codigo' => 'entrega_cliente', 'prazo_dias' => 3],
                ['codigo' => 'finalizado', 'exige_previsao_manual' => true],
            ],
            'consignacao' => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'consignado'],
                ['codigo' => 'devolucao_consignacao', 'prazo_dias' => 15],
                ['codigo' => 'finalizado', 'exige_previsao_manual' => true],
            ],
        ];
    }
};
