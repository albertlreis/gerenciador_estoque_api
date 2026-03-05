<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formas_pagamento', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 50)->unique();
            $table->string('slug', 60)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        $defaults = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];
        $now = now();

        $rows = array_map(static fn (string $nome) => [
            'nome' => $nome,
            'slug' => Str::slug($nome),
            'ativo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaults);

        DB::table('formas_pagamento')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('formas_pagamento');
    }
};
