<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_variacao_outlet_pagamentos', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('produto_variacao_outlet_id');
            $table->unsignedBigInteger('forma_pagamento_id');

            $table->decimal('percentual_desconto', 5, 2);
            $table->unsignedTinyInteger('max_parcelas')->nullable();

            $table->timestamps();

            $table->unique(['produto_variacao_outlet_id', 'forma_pagamento_id'], 'uq_outlet_forma');

            $table->foreign('produto_variacao_outlet_id', 'fk_outlet_pagamento_outlet')
                ->references('id')->on('produto_variacao_outlets')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('forma_pagamento_id', 'fk_outlet_pagamento_forma')
                ->references('id')->on('outlet_formas_pagamento')
                ->onDelete('restrict')
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_outlet_pagamentos');
    }
};
