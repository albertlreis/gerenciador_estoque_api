<?php

namespace App\Console\Commands;

use App\Support\ResetImagens\ProdutoImagemResetService;
use Illuminate\Console\Command;
use RuntimeException;

class RelinkProdutoImagensResetCommand extends Command
{
    protected $signature = 'produtos:relink-imagens-reset
        {manifest_path : Caminho absoluto ou relativo do manifest.json exportado antes do reset.}';

    protected $description = 'Religa imagens de produtos e variacoes depois da reimportacao inicial.';

    public function handle(ProdutoImagemResetService $service): int
    {
        try {
            $result = $service->relink((string) $this->argument('manifest_path'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Manifest: ' . $result['manifest_absolute_path']);
        $this->line('Summary: ' . $result['summary_absolute_path']);
        $this->line('Pendencias: ' . $result['pending_absolute_path']);
        $this->line('Produtos religados: ' . $result['relinked_produtos']);
        $this->line('Variacoes religadas: ' . $result['relinked_variacoes']);
        $this->line('Pendencias: ' . $result['pending_total']);
        $this->info('Relink concluido. Revise o CSV de pendencias antes de reabrir a janela.');

        return self::SUCCESS;
    }
}
