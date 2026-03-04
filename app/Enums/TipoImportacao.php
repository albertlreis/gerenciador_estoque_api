<?php

namespace App\Enums;

/**
 * Tipos de importação de pedidos.
 * PDF: SIERRA, QUAKER, AVANTI (por fornecedor).
 * XML: apenas ADORNOS (NFe para adornos).
 */
enum TipoImportacao: string
{
    case PRODUTOS_PDF_SIERRA = 'PRODUTOS_PDF_SIERRA';
    case PRODUTOS_PDF_QUAKER = 'PRODUTOS_PDF_QUAKER';
    case PRODUTOS_PDF_AVANTI = 'PRODUTOS_PDF_AVANTI';
    case ADORNOS_XML_NFE = 'ADORNOS_XML_NFE';

    public function label(): string
    {
        return match ($this) {
            self::PRODUTOS_PDF_SIERRA => 'SIERRA',
            self::PRODUTOS_PDF_QUAKER => 'QUAKER',
            self::PRODUTOS_PDF_AVANTI => 'AVANTI',
            self::ADORNOS_XML_NFE => 'Adornos (NFe XML)',
        };
    }

    public function isPdf(): bool
    {
        return $this !== self::ADORNOS_XML_NFE;
    }

    public function isXml(): bool
    {
        return $this === self::ADORNOS_XML_NFE;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
