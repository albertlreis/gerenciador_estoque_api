<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integracao_bancaria_conexoes')) {
            Schema::create('integracao_bancaria_conexoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conta_financeira_id')
                    ->constrained('contas_financeiras')
                    ->restrictOnDelete();
                $table->string('provedor', 40)->default('bb_extratos')->index();
                $table->string('ambiente', 32)->default('producao')->index();
                $table->string('status', 32)->default('inativa')->index();
                $table->dateTime('ultima_sincronizacao_em')->nullable()->index();
                $table->date('ultimo_periodo_inicio')->nullable()->index();
                $table->date('ultimo_periodo_fim')->nullable()->index();
                $table->text('ultimo_erro')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();

                $table->unique(['conta_financeira_id', 'provedor'], 'ux_ibc_conta_provedor');
            });
        }

        if (Schema::hasTable('conciliacao_bancaria_importacoes')) {
            Schema::table('conciliacao_bancaria_importacoes', function (Blueprint $table) {
                if (!Schema::hasColumn('conciliacao_bancaria_importacoes', 'origem')) {
                    $table->string('origem', 32)->default('ofx')->index()->after('arquivo_hash');
                }
                if (!Schema::hasColumn('conciliacao_bancaria_importacoes', 'origem_referencia')) {
                    $table->string('origem_referencia', 190)->nullable()->index()->after('origem');
                }
            });
        }

        if (Schema::hasTable('conciliacao_bancaria_transacoes')) {
            Schema::table('conciliacao_bancaria_transacoes', function (Blueprint $table) {
                if (!Schema::hasColumn('conciliacao_bancaria_transacoes', 'origem')) {
                    $table->string('origem', 32)->default('ofx')->index()->after('hash_unico');
                }
                if (!Schema::hasColumn('conciliacao_bancaria_transacoes', 'origem_transacao_id')) {
                    $table->string('origem_transacao_id', 190)->nullable()->index()->after('origem');
                }
                if (!Schema::hasColumn('conciliacao_bancaria_transacoes', 'raw_json')) {
                    $table->json('raw_json')->nullable()->after('memo');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conciliacao_bancaria_transacoes')) {
            Schema::table('conciliacao_bancaria_transacoes', function (Blueprint $table) {
                foreach (['raw_json', 'origem_transacao_id', 'origem'] as $column) {
                    if (Schema::hasColumn('conciliacao_bancaria_transacoes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('conciliacao_bancaria_importacoes')) {
            Schema::table('conciliacao_bancaria_importacoes', function (Blueprint $table) {
                foreach (['origem_referencia', 'origem'] as $column) {
                    if (Schema::hasColumn('conciliacao_bancaria_importacoes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('integracao_bancaria_conexoes');
    }
};
