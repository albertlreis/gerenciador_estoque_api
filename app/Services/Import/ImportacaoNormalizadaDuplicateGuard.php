<?php

namespace App\Services\Import;

use App\Enums\ImportacaoNormalizadaStatus;
use App\Exceptions\ImportacaoNormalizadaDuplicadaException;
use App\Models\ImportacaoNormalizada;

final class ImportacaoNormalizadaDuplicateGuard
{
    public function assertPodeCriarStaging(string $arquivoHash): void
    {
        $existente = $this->buscarImportacaoConflitante($arquivoHash, [
            ImportacaoNormalizadaStatus::RECEBIDA->value,
            ImportacaoNormalizadaStatus::STAGED->value,
            ImportacaoNormalizadaStatus::EM_REVISAO->value,
            ImportacaoNormalizadaStatus::PRONTA_PARA_EFETIVAR->value,
            ImportacaoNormalizadaStatus::CONFIRMADA->value,
            ImportacaoNormalizadaStatus::EM_PROCESSAMENTO->value,
            ImportacaoNormalizadaStatus::EFETIVADA->value,
        ]);

        if ($existente === null) {
            return;
        }

        throw new ImportacaoNormalizadaDuplicadaException(
            $existente,
            sprintf(
                'Já existe uma importação com o mesmo arquivo_hash (%s) em andamento ou efetivada. Importação existente: #%d.',
                $arquivoHash,
                $existente->id
            )
        );
    }

    public function assertPodeAvancarFluxo(ImportacaoNormalizada $importacao): void
    {
        if (!is_string($importacao->arquivo_hash) || trim($importacao->arquivo_hash) === '') {
            return;
        }

        $existente = $this->buscarImportacaoConflitante($importacao->arquivo_hash, [
            ImportacaoNormalizadaStatus::CONFIRMADA->value,
            ImportacaoNormalizadaStatus::EM_PROCESSAMENTO->value,
            ImportacaoNormalizadaStatus::EFETIVADA->value,
        ], $importacao->id);

        if ($existente === null) {
            return;
        }

        throw new ImportacaoNormalizadaDuplicadaException(
            $existente,
            sprintf(
                'A importação #%d não pode avançar porque já existe outra importação com o mesmo arquivo_hash (%s) em estado confirmado/processando/efetivado. Importação conflitante: #%d.',
                $importacao->id,
                $importacao->arquivo_hash,
                $existente->id
            )
        );
    }

    private function buscarImportacaoConflitante(
        string $arquivoHash,
        array $statuses,
        ?int $exceptId = null
    ): ?ImportacaoNormalizada {
        $query = ImportacaoNormalizada::query()
            ->where('arquivo_hash', $arquivoHash)
            ->whereIn('status', $statuses)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->first();
    }
}
