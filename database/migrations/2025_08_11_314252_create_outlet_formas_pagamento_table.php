<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_formas_pagamento', function (Blueprint $t){
            $t->id();
            $t->string('slug')->unique();
            $t->string('nome');
            $t->unsignedTinyInteger('max_parcelas_default')->nullable();
            $t->decimal('percentual_desconto_default', 5, 2)->nullable();
            $t->boolean('ativo')->default(true);
            $t->timestamps();

            $t->index(['ativo','nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_formas_pagamento');
    }
};
