<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas_recorrentes', function (Blueprint $table) {
            $table->string('direcao', 20)->default('PAGAR')->index();

            $table->unsignedInteger('cliente_id')->nullable()->index();
            $table->foreign('cliente_id')
                ->references('id')
                ->on('clientes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->unsignedSmallInteger('ocorrencias_total')->nullable();
        });

        DB::table('despesas_recorrentes')
            ->whereNull('direcao')
            ->orWhere('direcao', '')
            ->update(['direcao' => 'PAGAR']);

        Schema::table('despesa_recorrente_execucoes', function (Blueprint $table) {
            $table->unsignedBigInteger('conta_receber_id')->nullable()->index();
            $table->foreign('conta_receber_id')
                ->references('id')
                ->on('contas_receber')
                ->nullOnDelete();
        });

        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->foreignId('despesa_recorrente_id')->nullable()
                ->constrained('despesas_recorrentes')
                ->nullOnDelete();
            $table->date('recorrencia_competencia')->nullable()->index();
            $table->index(['despesa_recorrente_id', 'data_vencimento'], 'ix_cp_recorrencia_vencimento');
        });

        Schema::table('contas_receber', function (Blueprint $table) {
            $table->foreignId('despesa_recorrente_id')->nullable()
                ->constrained('despesas_recorrentes')
                ->nullOnDelete();
            $table->date('recorrencia_competencia')->nullable()->index();
            $table->index(['despesa_recorrente_id', 'data_vencimento'], 'ix_cr_recorrencia_vencimento');
        });

        DB::table('despesa_recorrente_execucoes')
            ->whereNotNull('conta_pagar_id')
            ->orderBy('id')
            ->select(['despesa_recorrente_id', 'conta_pagar_id', 'competencia'])
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('contas_pagar')
                        ->where('id', $row->conta_pagar_id)
                        ->update([
                            'despesa_recorrente_id' => $row->despesa_recorrente_id,
                            'recorrencia_competencia' => $row->competencia,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('contas_receber', function (Blueprint $table) {
            $table->dropIndex('ix_cr_recorrencia_vencimento');
            $table->dropIndex(['recorrencia_competencia']);
            $table->dropForeign(['despesa_recorrente_id']);
            $table->dropColumn(['despesa_recorrente_id', 'recorrencia_competencia']);
        });

        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->dropIndex('ix_cp_recorrencia_vencimento');
            $table->dropIndex(['recorrencia_competencia']);
            $table->dropForeign(['despesa_recorrente_id']);
            $table->dropColumn(['despesa_recorrente_id', 'recorrencia_competencia']);
        });

        Schema::table('despesa_recorrente_execucoes', function (Blueprint $table) {
            $table->dropForeign(['conta_receber_id']);
            $table->dropIndex(['conta_receber_id']);
            $table->dropColumn('conta_receber_id');
        });

        Schema::table('despesas_recorrentes', function (Blueprint $table) {
            $table->dropIndex(['direcao']);
            $table->dropForeign(['cliente_id']);
            $table->dropIndex(['cliente_id']);
            $table->dropColumn(['direcao', 'cliente_id', 'ocorrencias_total']);
        });
    }
};
