<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('is_outlet')->default(false)->after('ativo');
            $table->date('data_ultima_saida')->nullable()->after('is_outlet');
        });

        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->string('valor');
            $table->timestamps();
        });

        DB::table('configuracoes')->insert([
            [
                'chave' => 'dias_para_outlet',
                'valor' => '180',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'chave' => 'desconto_maximo_outlet',
                'valor' => '30',
                'created_at' => now(),
                'updated_at' => now()
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['is_outlet', 'data_ultima_saida']);
        });

        Schema::dropIfExists('configuracoes');
    }
};
