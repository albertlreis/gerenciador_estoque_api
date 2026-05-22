<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notas_fiscais')) {
            return;
        }

        Schema::create('notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loja_id')->nullable()->index();
            $table->string('chave_acesso', 60)->unique();
            $table->string('numero_nota', 30)->nullable()->index();
            $table->string('status', 40)->nullable()->index();
            $table->dateTime('data_emissao')->nullable()->index();
            $table->string('nome_destinatario', 190)->nullable();
            $table->string('documento_local_type', 190)->nullable()->index();
            $table->unsignedBigInteger('documento_local_id')->nullable()->index();
            $table->string('origem', 40)->default('conta_azul')->index();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['documento_local_type', 'documento_local_id'], 'nf_doc_local_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_fiscais');
    }
};
