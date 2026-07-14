<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_entrega_eventos', function (Blueprint $table) {
            $table->dateTime('ocorrido_em')->nullable()->after('tipo_evento')->index();
        });

        DB::table('produto_entrega_eventos')
            ->whereNull('ocorrido_em')
            ->update(['ocorrido_em' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('produto_entrega_eventos', function (Blueprint $table) {
            $table->dropIndex(['ocorrido_em']);
            $table->dropColumn('ocorrido_em');
        });
    }
};
