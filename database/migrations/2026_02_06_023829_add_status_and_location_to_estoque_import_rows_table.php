<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('estoque_import_rows', function (Blueprint $table) {
            if (!Schema::hasColumn('estoque_import_rows', 'status')) {
                $table->string('status', 80)->nullable()->after('deposito');
            }
            if (!Schema::hasColumn('estoque_import_rows', 'setor')) {
                $table->string('setor', 30)->nullable()->after('localizacao');
            }
            if (!Schema::hasColumn('estoque_import_rows', 'coluna')) {
                $table->string('coluna', 5)->nullable()->after('setor');
            }
            if (!Schema::hasColumn('estoque_import_rows', 'nivel')) {
                $table->integer('nivel')->nullable()->after('coluna');
            }
            if (!Schema::hasColumn('estoque_import_rows', 'area')) {
                $table->string('area', 255)->nullable()->after('nivel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('estoque_import_rows', function (Blueprint $table) {
            foreach (['status', 'setor', 'coluna', 'nivel', 'area'] as $col) {
                if (Schema::hasColumn('estoque_import_rows', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
