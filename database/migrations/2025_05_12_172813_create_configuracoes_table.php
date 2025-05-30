<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->string('label')->nullable();
            $table->string('tipo')->default('string'); // string, number, boolean
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
            [
                'chave' => 'prazo_envio_fabrica',
                'valor' => '5',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'chave' => 'prazo_entrega_estoque',
                'valor' => '7',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'chave' => 'prazo_envio_cliente',
                'valor' => '3',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'chave' => 'prazo_consignacao',
                'valor' => '15',
                'created_at' => now(),
                'updated_at' => now()
            ],

        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
    }
};
