<?php

namespace App\Exports;

use App\DTOs\FiltroEstoqueDTO;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EstoqueAtualConferenciaExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    private const COLUNAS = [
        'produto' => 'Produto',
        'referencia' => 'Referencia',
        'deposito' => 'Deposito',
        'quantidade' => 'Quantidade',
        'estoque_cliente' => 'Estoque cliente',
        'localizacao' => 'Localizacao',
        'area' => 'Area',
        'corredor' => 'Corredor',
        'setor' => 'Setor',
        'coluna' => 'Coluna',
        'nivel' => 'Nivel',
        'custo_unitario' => 'Custo unitario',
        'data_entrada' => 'Entrada',
        'ultima_venda' => 'Ultima venda',
        'dias_sem_venda' => 'Dias sem venda',
    ];

    private const DEFAULT_COLUNAS = [
        'produto',
        'referencia',
        'deposito',
        'quantidade',
        'estoque_cliente',
        'localizacao',
        'dias_sem_venda',
    ];

    /** @param Collection<int, mixed>|array<int, mixed> $estoque */
    public function __construct(
        private readonly Collection|array $estoque,
        private readonly FiltroEstoqueDTO $filtros
    ) {}

    /** @return array<int, string> */
    public static function normalizarColunas(array $colunas): array
    {
        $permitidas = array_keys(self::COLUNAS);
        $selecionadas = array_values(array_intersect($colunas, $permitidas));

        return $selecionadas ?: self::DEFAULT_COLUNAS;
    }

    /** @return array<string, string> */
    public static function definicoes(array $colunas): array
    {
        $normalizadas = self::normalizarColunas($colunas);

        return collect($normalizadas)
            ->mapWithKeys(fn (string $coluna) => [$coluna => self::COLUNAS[$coluna]])
            ->all();
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_values(self::definicoes($this->filtros->colunas));
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $colunas = array_keys(self::definicoes($this->filtros->colunas));

        return collect(self::linhas($this->estoque, $this->filtros))
            ->map(fn (array $linha) => array_map(fn (string $coluna) => $linha[$coluna] ?? null, $colunas))
            ->values()
            ->all();
    }

    /** @param Collection<int, mixed>|array<int, mixed> $estoque */
    public static function linhas(Collection|array $estoque, FiltroEstoqueDTO $filtros): array
    {
        $linhas = [];

        foreach (collect($estoque) as $item) {
            $estoques = $item->estoquesComLocalizacao instanceof Collection
                ? $item->estoquesComLocalizacao
                : collect();

            if ($filtros->zerados || $estoques->isEmpty()) {
                $linhas[] = self::montarLinha($item, null);
                continue;
            }

            foreach ($estoques as $estoqueItem) {
                $linhas[] = self::montarLinha($item, $estoqueItem);
            }
        }

        return $linhas;
    }

    /** @return array<string, mixed> */
    private static function montarLinha($item, $estoqueItem): array
    {
        $localizacao = $estoqueItem?->localizacao;

        return [
            'produto' => $item->nome_completo,
            'referencia' => $item->sku_interno ?: ($item->referencia ?: $item->chave_variacao),
            'deposito' => $estoqueItem?->deposito?->nome ?? '-',
            'quantidade' => (int) ($estoqueItem?->quantidade ?? $item->quantidade_estoque ?? 0),
            'estoque_cliente' => (int) ($estoqueItem?->quantidade_reservada_cliente ?? $item->quantidade_reservada_cliente ?? 0),
            'localizacao' => self::formatarLocalizacao($localizacao),
            'area' => $localizacao?->area,
            'corredor' => $localizacao?->corredor,
            'setor' => $localizacao?->setor,
            'coluna' => $localizacao?->coluna,
            'nivel' => $localizacao?->nivel,
            'custo_unitario' => $item->custo !== null ? (float) $item->custo : null,
            'data_entrada' => self::formatarData($estoqueItem?->data_entrada_estoque_atual ?? $item->data_entrada_estoque_atual ?? null),
            'ultima_venda' => self::formatarData($estoqueItem?->ultima_venda_em ?? $item->ultima_venda_em ?? null),
            'dias_sem_venda' => $item->dias_sem_venda !== null ? (int) $item->dias_sem_venda : null,
        ];
    }

    private static function formatarLocalizacao($localizacao): string
    {
        if (!$localizacao) {
            return '-';
        }

        $partes = array_filter([
            self::valorLocalizacao($localizacao->area),
            self::valorLocalizacao($localizacao->corredor),
            self::valorLocalizacao($localizacao->setor),
            self::valorLocalizacao($localizacao->coluna),
            self::valorLocalizacao($localizacao->nivel),
        ], fn ($valor) => $valor !== null);

        if ($partes) {
            return implode('-', $partes);
        }

        return self::limparCodigoLocalizacao($localizacao->codigo_composto) ?: '-';
    }

    private static function limparCodigoLocalizacao(mixed $codigo): ?string
    {
        $codigo = self::valorLocalizacao($codigo);
        if ($codigo === null) {
            return null;
        }

        $partes = array_filter(
            array_map(fn ($parte) => self::valorLocalizacao($parte), explode('-', $codigo)),
            fn ($parte) => $parte !== null
        );

        return $partes ? implode('-', $partes) : null;
    }

    private static function valorLocalizacao(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || preg_match('/^-+$/', $value) ? null : $value;
    }

    private static function formatarData($value): ?string
    {
        if (!$value) {
            return null;
        }

        return \Carbon\CarbonImmutable::parse($value)->format('d/m/Y');
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $sheet->getHighestColumn();

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAF2FF'],
            ],
        ]);

        return [];
    }
}
