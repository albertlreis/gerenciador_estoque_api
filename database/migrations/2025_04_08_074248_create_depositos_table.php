<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depositos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome', 255);
            $table->text('endereco')->nullable();
            $table->timestamps();

            $table->index('nome', 'idx_depositos_nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositos');
    }
};
