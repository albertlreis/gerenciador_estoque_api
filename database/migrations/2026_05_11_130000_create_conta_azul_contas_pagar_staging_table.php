<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stg_conta_azul_contas_pagar')) {
            return;
        }

        Schema::create('stg_conta_azul_contas_pagar', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loja_id')->nullable()->index();
            $table->string('identificador_externo', 190);
            $table->json('payload_json');
            $table->string('hash_payload', 64)->index();
            $table->string('status_conciliacao', 32)->default('novo')->index();
            $table->text('observacao_conciliacao')->nullable();
            $table->unsignedBigInteger('candidato_id_local')->nullable()->index();
            $table->unsignedTinyInteger('candidato_score')->nullable()->index();
            $table->string('candidato_motivo', 255)->nullable();
            $table->json('candidato_json')->nullable();
            $table->string('conciliacao_origem', 32)->nullable()->index();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('conta_azul_import_batches')->nullOnDelete();
            $table->unique(['loja_id', 'identificador_externo'], 'stg_ca_contas_pagar_loja_ext_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_conta_azul_contas_pagar');
    }
};
