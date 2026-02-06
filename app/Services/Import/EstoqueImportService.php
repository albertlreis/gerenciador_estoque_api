<?php

namespace App\Services\Import;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\AreaEstoque;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueImport;
use App\Models\EstoqueImportRow;
use App\Services\EstoqueMovimentacaoService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class EstoqueImportService
{
    public function __construct(
        private LocalizacaoParser    $locParser,
        private DimensoesParser      $dimParser,
        private ProdutoUpsertService $produtoUpsert,
    ) {}

    /**
     * Novo formato da planilha (aba "Estoque"):
     * - Qd, Codigo (texto), Deposito (apenas Loja/Depósito), Status, Nome, Categoria, Madeira, Tec. 1, Tec. 2, Metal / Vidro
     * - Localização em colunas: Setor, Coluna, Nivel, Area (e pode existir a coluna antiga "Localização" como fallback)
     * - Dimensões (se existir): Largura, Altura, Profundidade (cm)
     *
     * Regras:
     * - Só movimenta/gera estoque quando Status = "Em estoque" (ou quando Status vazio mas Deposito oficial preenchido)
     * - Depósitos oficiais: Loja e Depósito
     * - Linhas com Status != "Em estoque": cadastra produto/variação/atributos e IGNORA quantidade (qtd vira 0)
     */
    public function criarStaging(UploadedFile $arquivo, ?int $usuarioId = null): EstoqueImport
    {
        $hash = hash_file('sha256', $arquivo->getRealPath());

        $import = EstoqueImport::create([
            'arquivo_nome' => $arquivo->getClientOriginalName(),
            'arquivo_hash' => $hash,
            'usuario_id'   => $usuarioId,
            'status'       => 'pendente',
        ]);

        $spreadsheet = IOFactory::load($arquivo->getRealPath());
        $sheet = $spreadsheet->getSheetByName('Estoque') ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headerMap = $this->mapHeaderNormalizado(array_shift($rows));

        $linhaNum = 2;
        $validos = $invalidos = 0;

        foreach ($rows as $r) {
            $row = $this->rowToAssocCanonico($r, $headerMap);

            $cod  = $this->toText($row['codigo'] ?? null);
            $nomeOriginal = trim((string)($row['nome'] ?? ''));

            $categoria = trim((string)($row['categoria'] ?? ''));
            $madeira = trim((string)($row['madeira'] ?? ''));
            $tec1 = trim((string)($row['tecido_1'] ?? ''));
            $tec2 = trim((string)($row['tecido_2'] ?? ''));
            $metalVidro = trim((string)($row['metal_vidro'] ?? ''));

            $depositoRaw = trim((string)($row['deposito'] ?? ''));
            $depositoNome = $this->normalizarDeposito($depositoRaw); // Loja | Depósito | null

            $statusRaw = trim((string)($row['status'] ?? ''));
            $statusNorm = $this->normalizarTexto($statusRaw);

            // status default (se depósito oficial e status vazio)
            if ($statusNorm === '' && $depositoNome) {
                $statusRaw = 'Em estoque';
                $statusNorm = 'em estoque';
            }

            // se status "em estoque" mas depósito vazio, assume Depósito e avisa
            $warnings = [];
            if ($this->isEmEstoque($statusNorm) && !$depositoNome) {
                $depositoNome = 'Depósito';
                $warnings[] = 'Depósito ausente; assumido "Depósito".';
            }

            $qtd = $this->toInt($row['qd'] ?? null);

            // Se NÃO estiver em estoque → ignora quantidade (não invalida a linha)
            $emEstoque = $depositoNome !== null && $this->isEmEstoque($statusNorm);

            if (!$emEstoque) {
                if (($qtd ?? 0) !== 0) {
                    $warnings[] = 'Qtd ignorada pois Status != "Em estoque" ou Depósito oficial ausente.';
                }
                $qtd = 0;
            }

            // Dimensões (preferir colunas; fallback parser se faltar)
            $w = $this->toDecimal($row['largura'] ?? null);
            $a = $this->toDecimal($row['altura'] ?? null);
            $p = $this->toDecimal($row['profundidade'] ?? null);

            $dim = [
                'full' => $nomeOriginal,
                'raw' => null,
                'clean' => $nomeOriginal,
                'w_cm' => $w,
                'p_cm' => $p,
                'a_cm' => $a,
                'diam_cm' => null,
            ];

            if (($w === null || $a === null || $p === null) && $nomeOriginal !== '') {
                $fb = $this->dimParser->extrair($nomeOriginal);
                $dim['clean'] = $dim['clean'] ?: ($fb['clean'] ?? $nomeOriginal);
                $dim['raw']   = $dim['raw']   ?: ($fb['raw'] ?? null);
                $dim['w_cm']  = $dim['w_cm']  ?? ($fb['w_cm'] ?? null);
                $dim['p_cm']  = $dim['p_cm']  ?? ($fb['p_cm'] ?? null);
                $dim['a_cm']  = $dim['a_cm']  ?? ($fb['a_cm'] ?? null);
                $dim['diam_cm'] = $dim['diam_cm'] ?? ($fb['diam_cm'] ?? null);
            }

            // Localização em colunas (sem parser) + fallback Localização antiga
            $setorRaw  = trim((string)($row['setor'] ?? ''));
            $colunaRaw = trim((string)($row['coluna'] ?? ''));
            $nivelRaw  = $row['nivel'] ?? null;
            $areaRaw   = trim((string)($row['area'] ?? ''));

            $nivel = $this->toInt($nivelRaw);

            $localizacaoLegacy = trim((string)($row['localizacao'] ?? ''));

            $loc = $this->buildLocalizacaoFromColumns(
                $setorRaw, $colunaRaw, $nivel, $areaRaw, $localizacaoLegacy
            );

            // preencher campo localizacao “simples” (string) compatível com o que vocês já tinham
            $localizacaoStr = $loc['codigo'] ?? null;

            $erros = [];
            // produto pode vir sem cod (vocês permitem), então deixei sem validar
            if ($qtd === null || $qtd < 0) $erros[] = 'Qtd inválida'; // aqui, qtd já virou 0 se não em estoque

            $hashLinha = hash('sha256', json_encode([
                $import->arquivo_hash,
                $linhaNum,
                $cod,
                $nomeOriginal,
                $depositoNome,
                $statusRaw,
            ], JSON_UNESCAPED_UNICODE));

            EstoqueImportRow::create([
                'import_id' => $import->id,
                'linha_planilha' => $linhaNum,
                'hash_linha' => $hashLinha,

                'cod' => $cod,
                'nome' => $nomeOriginal,
                'categoria' => $categoria,

                'madeira' => $madeira !== '' ? $madeira : null,
                'tecido_1' => $tec1 !== '' ? $tec1 : null,
                'tecido_2' => $tec2 !== '' ? $tec2 : null,
                'metal_vidro' => $metalVidro !== '' ? $metalVidro : null,

                'deposito' => $depositoNome,
                'status' => $statusRaw !== '' ? $statusRaw : null,

                'localizacao' => $localizacaoStr,
                'setor' => $setorRaw !== '' ? $setorRaw : null,
                'coluna' => $colunaRaw !== '' ? strtoupper($colunaRaw) : null,
                'nivel' => $nivel,
                'area' => $areaRaw !== '' ? $areaRaw : null,

                // campos ignorados na regra (mantidos como null)
                'cliente' => null,
                'data_nf' => null,
                'data' => null,
                'valor' => null,

                'qtd' => $qtd,

                'parsed_dimensoes' => $dim,
                'parsed_localizacao' => $loc,

                'valido' => empty($erros),
                'erros' => $erros ?: null,
                'warnings' => $warnings ?: null,
            ]);

            if ($erros) $invalidos++; else $validos++;
            $linhaNum++;
        }

        $import->update([
            'linhas_total' => $validos + $invalidos,
            'linhas_validas' => $validos,
            'linhas_invalidas' => $invalidos,
        ]);

        return $import;
    }

    public function processar(EstoqueImport $import, bool $dryRun = false): array
    {
        $import->update(['status' => 'processando', 'mensagem' => null]);

        $movCriadas = 0;

        // Agrupar por (cod + atributos + depósito efetivo + local efetivo + categoria)
        $grupos = $import->rows()
            ->where('valido', true)
            ->get()
            ->groupBy(function (EstoqueImportRow $r) {

                $depositoNome = $this->normalizarDeposito((string)$r->deposito);
                $statusNorm = $this->normalizarTexto((string)$r->status);

                $emEstoque = $depositoNome !== null && $this->isEmEstoque($statusNorm);

                $attrs = [
                    'madeira' => $r->madeira,
                    'tecido_1' => $r->tecido_1,
                    'tecido_2' => $r->tecido_2,
                    'metal_vidro' => $r->metal_vidro,
                ];
                $attrsKey = md5(json_encode($attrs, JSON_UNESCAPED_UNICODE));

                $depKey = $emEstoque ? $depositoNome : 'NULL_DEP';
                $locKey = $emEstoque ? (string)($r->localizacao ?? 'NULL_LOC') : 'NULL_LOC';

                return implode('|', [
                    (string)$r->cod,
                    $attrsKey,
                    $depKey,
                    $locKey,
                    (string)$r->categoria,
                ]);
            });

        DB::beginTransaction();
        try {
            foreach ($grupos as $linhas) {
                /** @var \Illuminate\Support\Collection<int,EstoqueImportRow> $linhas */
                $primeira = $linhas->first();
                $cod = (string)$primeira->cod;

                // Nome escolhido
                $nomeEscolhido = $linhas->pluck('nome')->sortByDesc(fn($n) => mb_strlen((string)$n))->first();
                $nomeEscolhido = (string)($nomeEscolhido ?? '');

                // Dimensões preferindo parsed_dimensoes já calculado no staging
                $dim = $this->pickDimensoes($linhas, $nomeEscolhido);
                $nomeLimpo = trim((string)($dim['clean'] ?? $nomeEscolhido));
                if ($nomeLimpo === '') $nomeLimpo = $nomeEscolhido ?: 'Produto';

                // Categoria
                $categoriaId = null;
                if ($primeira->categoria) {
                    $cat = Categoria::firstOrCreate(['nome' => trim((string)$primeira->categoria)]);
                    $categoriaId = $cat->id;
                }

                // quantidade total (no staging já vira 0 quando não em estoque)
                $qtdTotal = (int)$linhas->sum(fn($l) => (int)($l->qtd ?? 0));

                // Upsert produto/variação/atributos
                $attrs = [
                    'madeira'   => $primeira->madeira,
                    'tecido_1'  => $primeira->tecido_1,
                    'tecido_2'  => $primeira->tecido_2,
                    'metal_vidro' => $primeira->metal_vidro,
                ];

                $up = $this->produtoUpsert->upsertProdutoVariacao([
                    'nome_limpo'    => $nomeLimpo,
                    'nome_completo' => $nomeEscolhido,
                    'categoria_id'  => $categoriaId,
                    'w_cm' => $dim['w_cm'] ?? null,
                    'p_cm' => $dim['p_cm'] ?? null,
                    'a_cm' => $dim['a_cm'] ?? null,
                    'valor' => null,
                    'cod'   => $cod,
                    'atributos' => $attrs,
                ]);

                $variacao = $up['variacao'];

                // Só cria estoque/movimentação se Em estoque + Depósito oficial
                $depositoNome = $this->normalizarDeposito((string)$primeira->deposito);
                $statusNorm = $this->normalizarTexto((string)$primeira->status);
                $emEstoque = $depositoNome !== null && $this->isEmEstoque($statusNorm);

                if ($emEstoque) {
                    $depositoModel = Deposito::firstOrCreate(['nome' => $depositoNome]);

                    // cria estoque base
                    $estoque = Estoque::firstOrCreate(
                        ['id_variacao' => $variacao->id, 'id_deposito' => $depositoModel->id],
                        ['quantidade' => 0]
                    );

                    // localização (sem parser; usa parsed_localizacao)
                    $locParsed = is_array($primeira->parsed_localizacao) ? $primeira->parsed_localizacao : null;

                    if (!$locParsed && $primeira->localizacao) {
                        $locParsed = $this->locParser->parse((string)$primeira->localizacao);
                    }

                    if ($locParsed && in_array($locParsed['tipo'] ?? null, ['posicao', 'area'], true)) {
                        $areaId = null;
                        $areaNome = (string)($locParsed['area'] ?? '');
                        if ($areaNome !== '') {
                            $area = AreaEstoque::firstOrCreate([
                                'nome' => mb_convert_case($areaNome, MB_CASE_TITLE, 'UTF-8')
                            ]);
                            $areaId = $area->id;
                        }

                        $estoque->localizacao()->updateOrCreate(
                            ['estoque_id' => $estoque->id],
                            [
                                'setor' => $locParsed['setor'] ?? null,
                                'coluna' => $locParsed['coluna'] ?? null,
                                'nivel' => $locParsed['nivel'] ?? null,
                                'area_id' => $areaId,
                                'codigo_composto' => $locParsed['codigo'] ?? null,
                            ]
                        );
                    }

                    // Movimentação inicial
                    if ($qtdTotal > 0) {
                        if (!$dryRun) {
                            app(EstoqueMovimentacaoService::class)->registrarMovimentacaoManual([
                                'id_variacao'         => (int)$variacao->id,
                                'id_deposito_origem'  => null,
                                'id_deposito_destino' => (int)$depositoModel->id,
                                'tipo'                => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                                'quantidade'          => (int)$qtdTotal,
                                'observacao'          => "Importação inicial #{$import->id}",
                                'data_movimentacao'   => now(),
                            ], $import->usuario_id);

                            $movCriadas++;
                        }
                    }
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            $import->update([
                'status' => 'concluido',
                'linhas_processadas' => $import->linhas_validas,
                'metricas' => [
                    'movimentacoes_criadas' => $movCriadas,
                    'grupos_processados' => $grupos->count(),
                ],
            ]);

            return [
                'sucesso' => true,
                'dry_run' => $dryRun,
                'movimentacoes_criadas' => $movCriadas,
                'grupos_processados' => $grupos->count(),
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            $import->update([
                'status' => 'com_erro',
                'mensagem' => $e->getMessage(),
            ]);
            return ['sucesso' => false, 'erro' => $e->getMessage()];
        }
    }

    // ----------------------------
    // Helpers
    // ----------------------------

    private function mapHeaderNormalizado(array $headerRow): array
    {
        // canônico => letra da coluna (A,B,C...)
        $map = [];

        $aliases = [
            'qd' => 'qd',
            'qtd' => 'qd',
            'quantidade' => 'qd',

            'codigo' => 'codigo',
            'código' => 'codigo',
            'cod' => 'codigo',

            'deposito' => 'deposito',
            'depósito' => 'deposito',

            'status' => 'status',

            'nome' => 'nome',

            'categoria' => 'categoria',

            'madeira' => 'madeira',

            'tec. 1' => 'tecido_1',
            'tec 1' => 'tecido_1',
            'tecido 1' => 'tecido_1',

            'tec. 2' => 'tecido_2',
            'tec 2' => 'tecido_2',
            'tecido 2' => 'tecido_2',

            'metal / vidro' => 'metal_vidro',
            'metal/vidro' => 'metal_vidro',
            'metal vidro' => 'metal_vidro',

            'localizacao' => 'localizacao',
            'localização' => 'localizacao',

            'setor' => 'setor',
            'coluna' => 'coluna',
            'nivel' => 'nivel',
            'nível' => 'nivel',
            'area' => 'area',
            'área' => 'area',

            'largura' => 'largura',
            'altura' => 'altura',
            'profundidade' => 'profundidade',
        ];

        foreach ($headerRow as $col => $name) {
            $raw = trim((string)$name);
            if ($raw === '') continue;

            $key = $this->normalizarTexto($raw);
            $key = preg_replace('/\s+/', ' ', $key);
            $canonical = $aliases[$key] ?? null;

            if ($canonical) {
                $map[$canonical] = $col;
            }
        }

        return $map;
    }

    private function rowToAssocCanonico(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $canonical => $col) {
            $out[$canonical] = $row[$col] ?? null;
        }
        return $out;
    }

    private function normalizarTexto(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = Str::of($s)->trim()->lower()->__toString();
        $s = Str::of($s)->ascii()->__toString();
        return $s;
    }

    private function normalizarDeposito(?string $raw): ?string
    {
        $n = $this->normalizarTexto((string)$raw);
        if ($n === '') return null;

        if (Str::contains($n, 'loja')) return 'Loja';
        if (Str::contains($n, 'deposito')) return 'Depósito';

        return null;
    }

    private function isEmEstoque(string $statusNorm): bool
    {
        // statusNorm já está lower+ascii
        if ($statusNorm === '') return false;
        return $statusNorm === 'em estoque';
    }

    private function buildLocalizacaoFromColumns(
        string $setorRaw,
        string $colunaRaw,
        ?int $nivel,
        string $areaRaw,
        string $legacy
    ): array {
        // Se setor for numérico e coluna for 1 letra, tratamos como posição (compatível com o schema antigo)
        $setorOk = ($setorRaw !== '' && ctype_digit($setorRaw));
        $colOk = ($colunaRaw !== '' && preg_match('/^[A-Za-z]$/', $colunaRaw));

        if ($setorOk && $colOk) {
            $setor = (int)$setorRaw;
            $coluna = strtoupper($colunaRaw);

            $codigo = $nivel === null
                ? sprintf('%d-%s', $setor, $coluna)
                : sprintf('%d-%s%d', $setor, $coluna, $nivel);

            return [
                'setor'  => $setor,
                'coluna' => $coluna,
                'nivel'  => $nivel,
                'area'   => $areaRaw !== '' ? $areaRaw : null,
                'codigo' => $codigo,
                'tipo'   => 'posicao',
            ];
        }

        // Se veio área, vira área
        if ($areaRaw !== '') {
            return [
                'setor' => null,
                'coluna' => null,
                'nivel' => null,
                'area' => $areaRaw,
                'codigo' => $areaRaw,
                'tipo' => 'area',
            ];
        }

        // fallback para coluna antiga
        if ($legacy !== '') {
            return $this->locParser->parse($legacy);
        }

        return [
            'setor' => null, 'coluna' => null, 'nivel' => null,
            'area' => null, 'codigo' => null, 'tipo' => 'vazio'
        ];
    }

    private function pickDimensoes($linhas, string $nomeEscolhido): array
    {
        foreach ($linhas as $l) {
            $d = $l->parsed_dimensoes;
            if (is_array($d) && (
                    ($d['w_cm'] ?? null) !== null ||
                    ($d['p_cm'] ?? null) !== null ||
                    ($d['a_cm'] ?? null) !== null ||
                    ($d['clean'] ?? null) !== null
                )) {
                return $d;
            }
        }
        return $this->dimParser->extrair($nomeEscolhido);
    }

    private function toDecimal(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;

        if (is_numeric($v)) return (float)$v;

        $s = trim((string)$v);
        if ($s === '') return null;

        // se tem "." e "," => assume "." milhar e "," decimal
        if (str_contains($s, '.') && str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',')) {
            // só vírgula => decimal
            $s = str_replace(',', '.', $s);
        }
        $s = preg_replace('/\s+/', '', $s);

        return is_numeric($s) ? (float)$s : null;
    }

    private function toInt(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)$v;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    private function toText(mixed $v): string
    {
        if ($v === null) return '';
        if ($v instanceof DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($v))->format('Y/m/d');
        }
        if (is_string($v)) return trim($v);
        if (is_int($v)) return (string)$v;
        if (is_float($v)) {
            // evita notação científica
            if (floor($v) == $v) return (string)(int)$v;
            $s = rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
            return $s;
        }
        return trim((string)$v);
    }

    private function toDate(mixed $v): ?Carbon
    {
        if (!$v) return null;
        try {
            if ($v instanceof DateTimeInterface) return Carbon::instance(\DateTime::createFromInterface($v));
            return Carbon::parse((string)$v);
        } catch (Throwable) {
            return null;
        }
    }
}
