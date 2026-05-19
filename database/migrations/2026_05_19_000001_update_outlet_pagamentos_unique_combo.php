<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_variacao_outlet_pagamentos', function (Blueprint $table) {
            $table->unique(
                ['produto_variacao_outlet_id', 'forma_pagamento_id', 'percentual_desconto', 'max_parcelas'],
                'uq_outlet_forma_desconto_parcelas'
            );
            $table->dropUnique('uq_outlet_forma');
        });
    }

    public function down(): void
    {
        Schema::table('produto_variacao_outlet_pagamentos', function (Blueprint $table) {
            $table->unique(['produto_variacao_outlet_id', 'forma_pagamento_id'], 'uq_outlet_forma');
            $table->dropUnique('uq_outlet_forma_desconto_parcelas');
        });
    }
};
