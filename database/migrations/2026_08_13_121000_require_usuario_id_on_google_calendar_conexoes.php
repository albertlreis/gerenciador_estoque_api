<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('google_calendar_conexoes')
            || !Schema::hasColumn('google_calendar_conexoes', 'usuario_id')) {
            return;
        }

        if (DB::table('google_calendar_conexoes')->whereNull('usuario_id')->exists()) {
            throw new \RuntimeException('Existem conexoes Google Agenda sem usuario. Execute o backfill antes de continuar.');
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE google_calendar_conexoes MODIFY usuario_id BIGINT UNSIGNED NOT NULL'),
            'pgsql' => DB::statement('ALTER TABLE google_calendar_conexoes ALTER COLUMN usuario_id SET NOT NULL'),
            default => null,
        };
    }

    public function down(): void
    {
        if (!Schema::hasTable('google_calendar_conexoes')
            || !Schema::hasColumn('google_calendar_conexoes', 'usuario_id')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE google_calendar_conexoes MODIFY usuario_id BIGINT UNSIGNED NULL'),
            'pgsql' => DB::statement('ALTER TABLE google_calendar_conexoes ALTER COLUMN usuario_id DROP NOT NULL'),
            default => null,
        };
    }
};
