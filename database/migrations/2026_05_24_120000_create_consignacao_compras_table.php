<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignacao_compras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignacao_id');
            $table->foreignId('usuario_id')->comment('ID do usuario que registrou a venda');
            $table->integer('quantidade');
            $table->text('observacoes')->nullable();
            $table->timestamp('data_compra')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('cancelada_em')->nullable();
            $table->foreignId('cancelada_por')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->timestamps();

            $table->foreign('consignacao_id')
                ->references('id')->on('consignacoes')
                ->cascadeOnDelete();

            $table->foreign('usuario_id')
                ->references('id')->on('acesso_usuarios')
                ->restrictOnDelete()
                ->onUpdate('restrict');

            $table->foreign('cancelada_por')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });

        DB::statement("ALTER TABLE consignacoes MODIFY status ENUM('pendente','comprado','devolvido','parcial') NOT NULL DEFAULT 'pendente'");

        DB::statement("
            INSERT INTO consignacao_compras (
                consignacao_id,
                usuario_id,
                quantidade,
                observacoes,
                data_compra,
                created_at,
                updated_at
            )
            SELECT
                c.id,
                p.id_usuario,
                c.quantidade,
                'Registro gerado a partir de venda existente.',
                COALESCE(c.data_resposta, c.updated_at, NOW()),
                NOW(),
                NOW()
            FROM consignacoes c
            INNER JOIN pedidos p ON p.id = c.pedido_id
            WHERE c.status = 'comprado'
              AND p.id_usuario IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::table('consignacoes')
            ->where('status', 'parcial')
            ->update(['status' => 'pendente']);

        DB::statement("ALTER TABLE consignacoes MODIFY status ENUM('pendente','comprado','devolvido') NOT NULL DEFAULT 'pendente'");

        Schema::dropIfExists('consignacao_compras');
    }
};
