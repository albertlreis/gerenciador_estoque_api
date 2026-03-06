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
        DB::transaction(function () {
            $this->backfillSlugs('categorias_financeiras');
            $this->backfillSlugs('centros_custo');
        });

        Schema::table('categorias_financeiras', function (Blueprint $table) {
            $table->string('slug', 140)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('categorias_financeiras', function (Blueprint $table) {
            $table->string('slug', 140)->nullable()->change();
        });
    }

    private function backfillSlugs(string $table): void
    {
        DB::table($table)
            ->orderBy('id')
            ->get(['id', 'nome', 'slug'])
            ->each(function ($row) use ($table) {
                if (!empty($row->slug)) {
                    return;
                }

                $base = Str::slug((string) $row->nome);
                $slug = $base !== '' ? $base : 'item';
                $suffix = 2;

                while (DB::table($table)
                    ->where('slug', $slug)
                    ->where('id', '!=', $row->id)
                    ->exists()
                ) {
                    $slug = ($base !== '' ? $base : 'item') . '-' . $suffix;
                    $suffix++;
                }

                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['slug' => $slug]);
            });
    }
};
