<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_variacao_imagens', function (Blueprint $table) {
            $table->index('id_variacao', 'idx_pvi_variacao_fk');
        });

        Schema::table('produto_variacao_imagens', function (Blueprint $table) {
            $table->dropUnique('uq_pvi_variacao');
            $table->boolean('principal')->default(false)->after('url');
            $table->unsignedInteger('ordem')->default(0)->after('principal');
            $table->index(['id_variacao', 'principal', 'ordem'], 'idx_pvi_variacao_principal_ordem');
        });

        DB::table('produto_variacao_imagens')->update([
            'principal' => true,
            'ordem' => 0,
        ]);
    }

    public function down(): void
    {
        $idsParaManter = DB::table('produto_variacao_imagens')
            ->selectRaw('MIN(id) as id')
            ->groupBy('id_variacao')
            ->pluck('id')
            ->all();

        if (!empty($idsParaManter)) {
            DB::table('produto_variacao_imagens')
                ->whereNotIn('id', $idsParaManter)
                ->delete();
        }

        Schema::table('produto_variacao_imagens', function (Blueprint $table) {
            $table->dropIndex('idx_pvi_variacao_principal_ordem');
            $table->dropColumn(['principal', 'ordem']);
            $table->unique('id_variacao', 'uq_pvi_variacao');
        });

        Schema::table('produto_variacao_imagens', function (Blueprint $table) {
            $table->dropIndex('idx_pvi_variacao_fk');
        });
    }
};
