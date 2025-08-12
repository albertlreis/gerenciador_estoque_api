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
        Schema::create('outlet_formas_pagamento', function (Blueprint $t){
            $t->id();
            $t->string('slug')->unique(); // avista, boleto, cartao...
            $t->string('nome');           // À vista, Boleto, Cartão de Crédito
            $t->unsignedTinyInteger('max_parcelas_default')->nullable();
            $t->decimal('percentual_desconto_default',5,2)->nullable();
            $t->boolean('ativo')->default(true);
            $t->timestamps();
        });

        Schema::table('produto_variacao_outlet_pagamentos', function (Blueprint $t){
            $t->foreignId('forma_pagamento_id')->nullable()->after('produto_variacao_outlet_id')
                ->constrained('outlet_formas_pagamento');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('produto_variacao_outlet_pagamentos', fn(Blueprint $t) => $t->dropConstrainedForeignId('forma_pagamento_id'));
        Schema::dropIfExists('outlet_formas_pagamento');
    }
};
