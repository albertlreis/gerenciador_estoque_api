<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'stg_conta_azul_contas_financeiras',
        'stg_conta_azul_categorias_financeiras',
        'stg_conta_azul_centros_custo',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            $this->createStaging($tableName);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }

    private function createStaging(string $tableName): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($tableName) {
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
            $table->unique(['loja_id', 'identificador_externo'], $tableName . '_loja_ext_unq');
        });
    }
};
