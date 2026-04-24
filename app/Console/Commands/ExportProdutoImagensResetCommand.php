<?php

namespace App\Console\Commands;

use App\Support\ResetImagens\ProdutoImagemResetService;
use Illuminate\Console\Command;

class ExportProdutoImagensResetCommand extends Command
{
    protected $signature = 'produtos:export-imagens-reset
        {--allow-missing-files : Continua a exportacao mesmo quando houver imagens cadastradas sem arquivo no storage.}';

    protected $description = 'Exporta manifesto operacional das imagens de produtos e variacoes antes do reset.';

    public function handle(ProdutoImagemResetService $service): int
    {
        $result = $service->export((bool) $this->option('allow-missing-files'));

        $this->line('Manifest: ' . $result['manifest_absolute_path']);
        $this->line('Summary: ' . $result['summary_absolute_path']);
        $this->line('Pendencias: ' . $result['pending_absolute_path']);
        $this->line('Itens exportados: ' . $result['total_items']);

        if (!$result['success']) {
            $this->error('Exportacao interrompida: existem imagens cadastradas sem arquivo correspondente no storage.');
            $this->error('Use --allow-missing-files somente quando quiser seguir mesmo com pendencias de reenvio.');

            return self::FAILURE;
        }

        $this->info('Manifesto de imagens gerado com sucesso.');

        return self::SUCCESS;
    }
}
