<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financeiro_parcelamentos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20)->index();
            $table->string('descricao', 180);
            $table->string('numero_documento', 80)->nullable();
            $table->decimal('valor_total', 15, 2)->default(0);
            $table->decimal('valor_entrada', 15, 2)->default(0);
            $table->unsignedSmallInteger('quantidade_parcelas')->default(1);
            $table->unsignedSmallInteger('intervalo_meses')->default(1);
            $table->date('data_emissao')->nullable();
            $table->date('primeiro_vencimento')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->foreign('created_by')->references('id')->on('acesso_usuarios')->nullOnDelete()->onUpdate('restrict');
            $table->timestamps();

            $table->index(['tipo', 'created_at']);
        });

        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->foreignId('parcelamento_id')->nullable()->after('id')
                ->constrained('financeiro_parcelamentos')->nullOnDelete();
            $table->unsignedSmallInteger('parcela_numero')->nullable()->after('parcelamento_id');
            $table->unsignedSmallInteger('parcelas_total')->nullable()->after('parcela_numero');
            $table->boolean('is_entrada')->default(false)->after('parcelas_total')->index();
            $table->index(['parcelamento_id', 'parcela_numero'], 'ix_cp_parcelamento_parcela');
        });

        Schema::table('contas_receber', function (Blueprint $table) {
            $table->foreignId('parcelamento_id')->nullable()->after('id')
                ->constrained('financeiro_parcelamentos')->nullOnDelete();
            $table->unsignedSmallInteger('parcela_numero')->nullable()->after('parcelamento_id');
            $table->unsignedSmallInteger('parcelas_total')->nullable()->after('parcela_numero');
            $table->boolean('is_entrada')->default(false)->after('parcelas_total')->index();
            $table->index(['parcelamento_id', 'parcela_numero'], 'ix_cr_parcelamento_parcela');
        });
    }

    public function down(): void
    {
        Schema::table('contas_receber', function (Blueprint $table) {
            $table->dropIndex('ix_cr_parcelamento_parcela');
            $table->dropForeign(['parcelamento_id']);
            $table->dropColumn(['parcelamento_id', 'parcela_numero', 'parcelas_total', 'is_entrada']);
        });

        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->dropIndex('ix_cp_parcelamento_parcela');
            $table->dropForeign(['parcelamento_id']);
            $table->dropColumn(['parcelamento_id', 'parcela_numero', 'parcelas_total', 'is_entrada']);
        });

        Schema::dropIfExists('financeiro_parcelamentos');
    }
};
