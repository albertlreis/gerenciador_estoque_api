<?php
namespace App\Console\Commands;

use App\Services\Holidays\HolidaySyncService;
use Illuminate\Console\Command;

class SyncHolidays extends Command
{
    protected $signature = 'holidays:sync {--year=} {--uf=} {--only=all : all|nacionais|estaduais}';
    protected $description = 'Sincroniza feriados nacionais e/ou estaduais (UF).';

    public function handle(HolidaySyncService $svc): int
    {
        $year = (int)($this->option('year') ?? now('America/Belem')->year);
        $only = $this->option('only') ?? 'all';
        $uf   = $this->option('uf') ?? config('holidays.default_uf', 'PA');

        $total = 0;

        if ($only === 'all' || $only === 'nacionais') {
            $n = $svc->syncNacionais($year);
            $this->info("Nacionais {$year}: +{$n}");
            $total += $n;
        }
        if ($only === 'all' || $only === 'estaduais') {
            $e = $svc->syncEstaduais($year, $uf);
            $this->info("Estaduais {$uf}/{$year}: +{$e}");
            $total += $e;
        }

        $this->info("Total inserido/atualizado: {$total}");
        return self::SUCCESS;
    }
}
