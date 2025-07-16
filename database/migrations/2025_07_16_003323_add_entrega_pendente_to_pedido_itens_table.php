<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEntregaPendenteToPedidoItensTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->boolean('entrega_pendente')->default(false)->after('quantidade');
            $table->timestamp('data_liberacao_entrega')->nullable()->after('entrega_pendente');
            $table->text('observacao_entrega_pendente')->nullable()->after('data_liberacao_entrega');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropColumn(['entrega_pendente', 'data_liberacao_entrega', 'observacao_entrega_pendente']);
        });
    }
}
