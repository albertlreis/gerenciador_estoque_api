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
        Schema::create('outlet_motivos', function (Blueprint $t){
            $t->id();
            $t->string('slug')->unique();   // ex: tempo_estoque
            $t->string('nome');             // ex: Tempo em estoque
            $t->boolean('ativo')->default(true);
            $t->timestamps();
        });

        Schema::table('produto_variacao_outlets', function (Blueprint $t){
            $t->foreignId('motivo_id')->nullable()->after('motivo')->constrained('outlet_motivos');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('produto_variacao_outlets', fn(Blueprint $t) => $t->dropConstrainedForeignId('motivo_id'));
        Schema::dropIfExists('outlet_motivos');
    }
};
