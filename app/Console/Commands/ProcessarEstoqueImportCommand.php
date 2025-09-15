<?php

namespace App\Console\Commands;

use App\Models\EstoqueImport;
use App\Services\Import\EstoqueImportService;
use Illuminate\Console\Command;

class ProcessarEstoqueImportCommand extends Command
{
    protected $signature = 'estoque:processar-import {importId} {--dry-run}';
    protected $description = 'Processa uma importação de estoque (staging → base), com opção de dry-run';

    public function __construct(private readonly EstoqueImportService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $import = EstoqueImport::findOrFail((int)$this->argument('importId'));
        $dry = (bool)$this->option('dry-run');

        $res = $this->service->processar($import, $dry);
        $this->info(json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

        return $res['sucesso'] ? self::SUCCESS : self::FAILURE;
    }
}
