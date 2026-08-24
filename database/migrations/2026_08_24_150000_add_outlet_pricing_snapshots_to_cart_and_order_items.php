<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrinho_itens', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_pagamento_id')->nullable()->after('outlet_id');
            $table->decimal('outlet_preco_base', 12, 2)->nullable();
            $table->unsignedBigInteger('outlet_forma_pagamento_id')->nullable();
            $table->string('outlet_forma_pagamento')->nullable();
            $table->decimal('outlet_percentual_desconto', 5, 2)->nullable();
            $table->unsignedTinyInteger('outlet_max_parcelas')->nullable();
            $table->decimal('outlet_preco_final', 12, 2)->nullable();
            $table->index(['id_carrinho', 'id_variacao', 'outlet_id', 'outlet_pagamento_id'], 'carrinho_outlet_condicao_idx');
            $table->foreign('outlet_pagamento_id', 'carrinho_outlet_pagamento_fk')
                ->references('id')->on('produto_variacao_outlet_pagamentos')->nullOnDelete();
            $table->foreign('outlet_forma_pagamento_id', 'carrinho_outlet_forma_fk')
                ->references('id')->on('outlet_formas_pagamento')->nullOnDelete();
        });

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->unsignedBigInteger('outlet_pagamento_id')->nullable();
            $table->decimal('outlet_preco_base', 12, 2)->nullable();
            $table->unsignedBigInteger('outlet_forma_pagamento_id')->nullable();
            $table->string('outlet_forma_pagamento')->nullable();
            $table->decimal('outlet_percentual_desconto', 5, 2)->nullable();
            $table->unsignedTinyInteger('outlet_max_parcelas')->nullable();
            $table->decimal('outlet_preco_final', 12, 2)->nullable();
            $table->index(['outlet_id', 'outlet_pagamento_id'], 'pedido_itens_outlet_condicao_idx');
            $table->foreign('outlet_id', 'pedido_itens_outlet_fk')
                ->references('id')->on('produto_variacao_outlets')->nullOnDelete();
            $table->foreign('outlet_pagamento_id', 'pedido_itens_outlet_pagamento_fk')
                ->references('id')->on('produto_variacao_outlet_pagamentos')->nullOnDelete();
            $table->foreign('outlet_forma_pagamento_id', 'pedido_itens_outlet_forma_fk')
                ->references('id')->on('outlet_formas_pagamento')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropForeign('pedido_itens_outlet_fk');
            $table->dropForeign('pedido_itens_outlet_pagamento_fk');
            $table->dropForeign('pedido_itens_outlet_forma_fk');
            $table->dropIndex('pedido_itens_outlet_condicao_idx');
            $table->dropColumn(['outlet_id', 'outlet_pagamento_id', 'outlet_preco_base', 'outlet_forma_pagamento_id', 'outlet_forma_pagamento', 'outlet_percentual_desconto', 'outlet_max_parcelas', 'outlet_preco_final']);
        });
        Schema::table('carrinho_itens', function (Blueprint $table) {
            $table->dropForeign('carrinho_outlet_pagamento_fk');
            $table->dropForeign('carrinho_outlet_forma_fk');
            $table->dropIndex('carrinho_outlet_condicao_idx');
            $table->dropColumn(['outlet_pagamento_id', 'outlet_preco_base', 'outlet_forma_pagamento_id', 'outlet_forma_pagamento', 'outlet_percentual_desconto', 'outlet_max_parcelas', 'outlet_preco_final']);
        });
    }
};
