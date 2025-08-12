<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\Holidays\HolidaySyncService;

class FeriadosSeeder extends Seeder
{
    public function run(): void
    {
        /** @var HolidaySyncService $svc */
        $svc  = app(HolidaySyncService::class);
        $ano  = now('America/Belem')->year;
        $uf   = config('holidays.default_uf', 'PA');

        $svc->syncNacionais($ano);
        $svc->syncEstaduais($ano, $uf);
        $svc->syncNacionais($ano+1);
        $svc->syncEstaduais($ano+1, $uf);
    }
}
