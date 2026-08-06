<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('google_calendar_calendars') || Schema::hasColumn('google_calendar_calendars', 'visibility')) {
            return;
        }

        Schema::table('google_calendar_calendars', function (Blueprint $table) {
            $table->string('visibility', 16)->nullable()->default('private')->index()->after('enabled');
        });

        DB::table('google_calendar_calendars')->whereNull('visibility')->update(['visibility' => 'private']);
    }

    public function down(): void
    {
        if (Schema::hasTable('google_calendar_calendars') && Schema::hasColumn('google_calendar_calendars', 'visibility')) {
            Schema::table('google_calendar_calendars', function (Blueprint $table) {
                $table->dropIndex(['visibility']);
                $table->dropColumn('visibility');
            });
        }
    }
};
