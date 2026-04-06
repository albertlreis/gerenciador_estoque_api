<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

final class SpreadsheetWorksheetReader
{
    /**
     * @return array<int, array{title:string, rows:array<int, array<string, mixed>>}>
     */
    public function read(string $path): array
    {
        if (class_exists(\ZipArchive::class)) {
            return $this->readWithPhpSpreadsheet($path);
        }

        return $this->readWithPythonFallback($path);
    }

    /**
     * @return array<int, array{title:string, rows:array<int, array<string, mixed>>}>
     */
    private function readWithPhpSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $worksheets[] = [
                'title' => (string) $worksheet->getTitle(),
                'rows' => $worksheet->toArray(null, true, true, true),
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return $worksheets;
    }

    /**
     * @return array<int, array{title:string, rows:array<int, array<string, mixed>>}>
     */
    private function readWithPythonFallback(string $path): array
    {
        $script = base_path('scripts/read_xlsx_rows.py');
        if (!is_file($script)) {
            throw new RuntimeException("Script de fallback para XLSX não encontrado: {$script}");
        }

        $process = new Process(['python3', $script, $path]);
        $process->setTimeout(120);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                'Falha ao ler planilha XLSX via fallback Python: ' . trim($e->getProcess()->getErrorOutput() ?: $e->getMessage()),
                previous: $e
            );
        }

        try {
            /** @var array<int, array{title:string, rows:array<int, array<string, mixed>>}> $worksheets */
            $worksheets = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Falha ao interpretar saída JSON do fallback XLSX.', previous: $e);
        }

        return $worksheets;
    }
}
