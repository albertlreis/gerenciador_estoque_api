<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('google_calendar_conexoes')
            || Schema::hasColumn('google_calendar_conexoes', 'usuario_id')) {
            return;
        }

        Schema::table('google_calendar_conexoes', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id')->nullable()->after('id');
            $table->unique('usuario_id', 'gc_conexoes_usuario_unq');
            $table->foreign('usuario_id', 'gc_conexoes_usuario_fk')
                ->references('id')
                ->on('acesso_usuarios')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('google_calendar_conexoes')
            || !Schema::hasColumn('google_calendar_conexoes', 'usuario_id')) {
            return;
        }

        Schema::table('google_calendar_conexoes', function (Blueprint $table) {
            $table->dropForeign('gc_conexoes_usuario_fk');
            $table->dropUnique('gc_conexoes_usuario_unq');
            $table->dropColumn('usuario_id');
        });
    }
};
