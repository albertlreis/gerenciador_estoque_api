<?php

namespace App\Exceptions;

use App\Models\ImportacaoNormalizada;
use RuntimeException;

class ImportacaoNormalizadaDuplicadaException extends RuntimeException
{
    public function __construct(
        private readonly ImportacaoNormalizada $importacaoExistente,
        string $message
    ) {
        parent::__construct($message);
    }

    public function importacaoExistente(): ImportacaoNormalizada
    {
        return $this->importacaoExistente;
    }
}
