<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // Atualiza ano atual trimestralmente
        $schedule->command('holidays:sync --year='.now()->year.' --uf=PA --only=all')
            ->quarterly()
            ->timezone('America/Belem');

        // Atualiza próximo ano mensalmente (de ago até jan)
        $schedule->command('holidays:sync --year='.(now()->year+1).' --uf=PA --only=all')
            ->monthly()
            ->when(fn() => now()->month >= 8 || now()->month <= 1)
            ->timezone('America/Belem');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
