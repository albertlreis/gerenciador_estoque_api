<?php

use App\Services\ProdutoDimensaoLegacyCleanupService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_variacao_dimensao_auditorias', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('produto_variacao_atributo_id')->nullable()->unique('uk_pv_dim_audit_attr');
            $table->unsignedInteger('variacao_id')->index('idx_pv_dim_audit_variacao');
            $table->string('atributo_legado', 100);
            $table->string('valor_legado', 100)->nullable();
            $table->string('campo_destino', 40)->nullable();
            $table->decimal('valor_anterior', 10, 2)->nullable();
            $table->decimal('valor_final', 10, 2)->nullable();
            $table->string('acao', 60);
            $table->timestamps();
        });

        app(ProdutoDimensaoLegacyCleanupService::class)->executar();
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_dimensao_auditorias');
    }
};
