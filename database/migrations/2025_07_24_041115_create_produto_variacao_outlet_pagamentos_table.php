<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('produto_variacao_outlet_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('produto_variacao_outlet_id');

            $table->foreign('produto_variacao_outlet_id', 'fk_outlet_pagamento')
                ->references('id')
                ->on('produto_variacao_outlets')
                ->onDelete('cascade');

            $table->string('forma_pagamento');
            $table->decimal('percentual_desconto', 5);
            $table->unsignedTinyInteger('max_parcelas')->nullable();
            $table->timestamps();
        });


    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_outlet_pagamentos');
    }
};
