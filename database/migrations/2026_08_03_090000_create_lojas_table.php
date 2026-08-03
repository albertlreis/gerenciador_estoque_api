<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lojas')) {
            Schema::create('lojas', function (Blueprint $table): void {
                $table->id();
                $table->string('codigo', 80)->unique();
                $table->string('nome', 190);
                $table->boolean('ativo')->default(true)->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('conta_azul_conexoes') && Schema::hasColumn('conta_azul_conexoes', 'loja_id')) {
            $invalidReferences = DB::table('conta_azul_conexoes')
                ->whereNotNull('loja_id')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('lojas')
                        ->whereColumn('lojas.id', 'conta_azul_conexoes.loja_id');
                })
                ->count();

            if ($invalidReferences > 0) {
                throw new RuntimeException(
                    'Existem conexões Conta Azul com loja_id sem cadastro correspondente; classifique-as antes da migration.'
                );
            }

            Schema::table('conta_azul_conexoes', function (Blueprint $table): void {
                $table->foreign('loja_id', 'ca_conexoes_loja_fk')
                    ->references('id')
                    ->on('lojas')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conta_azul_conexoes')) {
            Schema::table('conta_azul_conexoes', function (Blueprint $table): void {
                $table->dropForeign('ca_conexoes_loja_fk');
            });
        }

        Schema::dropIfExists('lojas');
    }
};
