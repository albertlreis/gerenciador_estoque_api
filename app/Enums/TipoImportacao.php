<?php

namespace App\Enums;

/**
 * Tipos de importacao de pedidos.
 */
enum TipoImportacao: string
{
    case PRODUTOS_XML_FORNECEDORES = 'PRODUTOS_XML_FORNECEDORES';
    case ADORNOS_XML_NFE = 'ADORNOS_XML_NFE';

    public function label(): string
    {
        return match ($this) {
            self::PRODUTOS_XML_FORNECEDORES => 'Produtos XML Fornecedores',
            self::ADORNOS_XML_NFE => 'Adornos (NFe XML)',
        };
    }

    public function isPdf(): bool
    {
        return false;
    }

    public function isXml(): bool
    {
        return true;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
