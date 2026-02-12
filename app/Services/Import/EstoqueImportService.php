<?php

namespace App\Services\Import;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\AreaEstoque;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueImport;
use App\Models\EstoqueImportRow;
use App\Models\Fornecedor;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use App\Services\EstoqueMovimentacaoService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Throwable;

final class EstoqueImportService
{
    public function __construct(
        private LocalizacaoParser    $locParser,
        private DimensoesParser      $dimParser,
        private ProdutoUpsertService $produtoUpsert,
        private NomeAtributosParser  $nomeAttrParser,
    ) {}

    /**
     * SUPORTE Ã€ PLANILHA NORMALIZADA (MULTI-ABAS)
     *
     * Campos aceitos (aliases em mapHeaderNormalizado):
     * - Quantidade: Qd / Qtd / Quantidade / Unidade
     * - CÃ³digo: Codigo / Cod / Referencia
     * - DepÃ³sito: Deposito (ou inferido do nome da aba: Loja/DepÃ³sito; Adornos -> Loja por padrÃ£o)
     * - Status: Status (ou variaÃ§Ãµes)
     * - Nome: Nome ou Nome_normalizado
     * - DimensÃµes: largura_cm/altura_cm/profundidade_cm/diametro_cm + (opcionais) comprimento_cm/espessura_cm
     * - Cores/acabamentos: cor_primaria, cor_secundaria, acabamentos_detectados, tom_madeira_detectado
     *
     * Regras:
     * - Movimenta/gera estoque quando Status indicar item em estoque (ex.: Em estoque/Loja/DepÃ³sito/DisponÃ­vel)
     *   E DepÃ³sito oficial conhecido.
     * - DepÃ³sitos oficiais: Loja e DepÃ³sito.
     * - Linhas com Status "nÃ£o em estoque" (ex.: Vendido/Reservado/etc): cadastra produto/variaÃ§Ã£o/atributos e IGNORA quantidade (qtd vira 0).
     * - DimensÃµes e atributos detectados do nome (ex.: tampo_cor) ficam em parsed_dimensoes (JSON) e tambÃ©m sÃ£o enviados como atributos no upsert.
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

        $validos = 0;
        $invalidos = 0;
        $detectedNovoLayout = false;

        foreach ($spreadsheet->getWorksheetIterator() as $ws) {
            $sheetName = (string)$ws->getTitle();

            if ($this->shouldSkipSheet($sheetName)) {
                continue;
            }

            $rows = $ws->toArray(null, true, true, true);
            if (!$rows || count($rows) < 2) {
                continue;
            }

            $headerMap = $this->mapHeaderNormalizado(array_shift($rows));
            if (!$this->headerHasMinimum($headerMap)) {
                continue;
            }

            $detectedNovoLayout = $detectedNovoLayout || $this->isNovoLayoutHeader($headerMap);

            $linhaPlanilha = 2; // linha real dentro da aba (1 = header)
            foreach ($rows as $r) {
                $row = $this->rowToAssocCanonico($r, $headerMap);

                if ($this->isRowEmpty($row)) {
                    $linhaPlanilha++;
                    continue;
                }

                $cod = $this->toText($row['codigo'] ?? null);

                $nomeOriginal = trim((string)($row['nome'] ?? ''));
                $nomeNorm = trim((string)($row['nome_normalizado'] ?? ''));

                // nome completo (como vem na planilha)
                $nomeCompleto = $nomeOriginal !== '' ? $nomeOriginal : $nomeNorm;

                // separa nome base vs atributos (ex.: TAMPO BRANCO/PRETO)
                $np = $this->nomeAttrParser->extrair($nomeCompleto);
                $nomeBaseProduto = trim((string)($np['nome_base'] ?? ''));
                if ($nomeBaseProduto === '') $nomeBaseProduto = $nomeCompleto;

                $categoria = trim((string)($row['categoria'] ?? ''));
                $fornecedorNome = trim((string)($row['fornecedor'] ?? ''));

                // materiais
                $tomMadeira = trim((string)($row['tom_madeira'] ?? ''));
                $madeira = trim((string)($row['madeira'] ?? ''));
                if ($tomMadeira !== '') {
                    $madeira = $tomMadeira;
                }

                $tec1 = trim((string)($row['tecido_1'] ?? ''));
                $tec2 = trim((string)($row['tecido_2'] ?? ''));
                $metalVidro = trim((string)($row['metal_vidro'] ?? ''));

                $precoCusto = $this->toDecimal($row['preco_custo'] ?? null);
                $precoVenda = $this->toDecimal($row['preco_venda'] ?? null);
                $outletMarcado = $this->toBool($row['outlet'] ?? null);

                $dataEntradaRaw = $row['data_entrada'] ?? null;
                $dataEntrada = $this->toDateFlexible($dataEntradaRaw);
                $dataEntradaFoiInformada = $this->hasValue($dataEntradaRaw);

                $atributosLimposRaw = $this->toText($row['atributos_limpos'] ?? null);
                $atributosLimpos = $this->parseAtributosLimpos($atributosLimposRaw);

                $statusRaw = trim((string)($row['status'] ?? ''));
                $statusNorm = $this->normalizarTexto($statusRaw);

                $depositoFromStatus = $this->resolverDepositoPorStatus($statusRaw);
                $depositoRaw = trim((string)($row['deposito'] ?? ''));
                $depositoNome = $depositoFromStatus
                    ?? $this->normalizarDeposito($depositoRaw)
                    ?? $this->defaultDepositoFromSheet($sheetName);

                $isNovoLayout = isset($headerMap['fornecedor'])
                    || isset($headerMap['preco_custo'])
                    || isset($headerMap['preco_venda'])
                    || isset($headerMap['outlet'])
                    || isset($headerMap['data_entrada'])
                    || isset($headerMap['atributos_limpos'])
                    || isset($headerMap['nome_produto']);

                if (!$isNovoLayout && $statusNorm === '' && $depositoNome) {
                    $statusRaw = 'Em estoque';
                    $statusNorm = 'em estoque';
                }

                $warnings = [];
                $warnings[] = 'Aba: ' . $sheetName;

                $legacyMovimenta = !$isNovoLayout
                    && $depositoNome !== null
                    && $this->isEmEstoque($statusNorm);
                $movimentaEstoque = $depositoFromStatus !== null || $legacyMovimenta;

                $qtd = $this->toInt($row['qd'] ?? null);
                if ($qtd === null) {
                    $qtd = 0;
                    $warnings[] = 'Quantidade nao informada; usado 0.';
                }
                if (!$movimentaEstoque && $qtd > 0) {
                    $warnings[] = 'Status nao mapeado para Depósito JB/Loja; sem movimentacao.';
                }
                // DimensÃµes (preferir colunas; fallback parser)
                $w = $this->toDecimal($row['largura'] ?? null);
                $a = $this->toDecimal($row['altura'] ?? null);
                $p = $this->toDecimal($row['profundidade'] ?? null);

                $diam = $this->toDecimal($row['diametro'] ?? null);
                $comp = $this->toDecimal($row['comprimento'] ?? null);
                $esp  = $this->toDecimal($row['espessura'] ?? null);

                $dim = [
                    'full' => $nomeCompleto,
                    'raw' => null,
                    'clean' => $nomeNorm !== '' ? $nomeNorm : $nomeCompleto,
                    'w_cm' => $w,
                    'p_cm' => $p,
                    'a_cm' => $a,
                    'diam_cm' => $diam,
                    'comp_cm' => $comp,
                    'esp_cm'  => $esp,

                    // atributos do nome
                    'nome_base' => $nomeBaseProduto,
                    'atributos' => $np['atributos'] ?? null,
                    'tampo_cor' => $np['tampo_cor'] ?? null,

                    // cores/acabamentos (sem migration: fica no JSON)
                    'cor_primaria' => ($row['cor_primaria'] ?? null) ? trim((string)$row['cor_primaria']) : null,
                    'cor_secundaria' => ($row['cor_secundaria'] ?? null) ? trim((string)$row['cor_secundaria']) : null,
                    'acabamentos' => ($row['acabamentos'] ?? null) ? trim((string)$row['acabamentos']) : null,
                    'tom_madeira' => $tomMadeira !== '' ? $tomMadeira : null,

                    // metadados do novo layout
                    'fornecedor' => $fornecedorNome !== '' ? $fornecedorNome : null,
                    'preco_custo' => $precoCusto,
                    'preco_venda' => $precoVenda,
                    'outlet' => $outletMarcado,
                    'atributos_limpos' => $atributosLimpos,
                    'atributos_limpos_raw' => $atributosLimposRaw !== '' ? $atributosLimposRaw : null,
                    'status_original' => $statusRaw !== '' ? $statusRaw : null,
                    'movimenta_estoque' => $movimentaEstoque,
                    'layout_novo' => $isNovoLayout,
                    'data_entrada_informada' => $dataEntradaFoiInformada,
                    'data_entrada' => $dataEntrada?->toDateString(),
                ];

                // fallback de dimensÃµes / limpeza do nome via parser (se faltar)
                if (
                    ($w === null && $a === null && $p === null && $diam === null && $comp === null && $esp === null)
                    && $nomeCompleto !== ''
                ) {
                    $fb = $this->dimParser->extrair($nomeCompleto);

                    $dim['clean']   = $dim['clean'] ?: ($fb['clean'] ?? $nomeCompleto);
                    $dim['raw']     = $dim['raw']   ?: ($fb['raw'] ?? null);

                    $dim['w_cm']    = $dim['w_cm']  ?? ($fb['w_cm'] ?? null);
                    $dim['p_cm']    = $dim['p_cm']  ?? ($fb['p_cm'] ?? null);
                    $dim['a_cm']    = $dim['a_cm']  ?? ($fb['a_cm'] ?? null);

                    $dim['diam_cm'] = $dim['diam_cm'] ?? ($fb['diam_cm'] ?? null);
                    $dim['comp_cm'] = $dim['comp_cm'] ?? ($fb['comp_cm'] ?? null);
                    $dim['esp_cm']  = $dim['esp_cm']  ?? ($fb['esp_cm'] ?? null);
                }

                // LocalizaÃ§Ã£o em colunas + fallback (quando existir)
                $setorRaw  = trim((string)($row['setor'] ?? ''));
                $colunaRaw = trim((string)($row['coluna'] ?? ''));
                $nivelRaw  = $row['nivel'] ?? null;
                $areaRaw   = trim((string)($row['area'] ?? ''));

                $nivel = $this->toInt($nivelRaw);

                $localizacaoLegacy = trim((string)($row['localizacao'] ?? ''));

                $loc = $this->buildLocalizacaoFromColumns(
                    $setorRaw, $colunaRaw, $nivel, $areaRaw, $localizacaoLegacy
                );

                $localizacaoStr = $loc['codigo'] ?? null;

                $erros = [];
                if ($qtd < 0) {
                    $erros[] = 'Quantidade invalida (nao pode ser negativa).';
                }
                if ($nomeCompleto === '') {
                    $erros[] = 'Nome do produto nao informado.';
                }
                if ($categoria === '') {
                    $erros[] = 'Categoria nao informada.';
                }
                if ($dataEntradaFoiInformada && !$dataEntrada) {
                    $erros[] = 'Data de entrada invalida.';
                }

                $hashLinha = hash('sha256', json_encode([
                    $import->arquivo_hash,
                    $sheetName,
                    $linhaPlanilha,
                    $cod,
                    $nomeCompleto,
                    $categoria,
                    $fornecedorNome,
                    $depositoNome,
                    $statusRaw,
                    $qtd,
                ], JSON_UNESCAPED_UNICODE));

                EstoqueImportRow::create([
                    'import_id' => $import->id,
                    'linha_planilha' => $linhaPlanilha,
                    'hash_linha' => $hashLinha,

                    'cod' => $cod,
                    'nome' => $nomeCompleto !== '' ? $nomeCompleto : 'Produto',
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

                    // cliente armazena fornecedor para manter compatibilidade de schema atual
                    'cliente' => $fornecedorNome !== '' ? $fornecedorNome : null,
                    'data_nf' => null,
                    'data' => $dataEntrada?->toDateString(),
                    'valor' => $precoVenda,

                    'qtd' => $qtd,

                    'parsed_dimensoes' => $dim,
                    'parsed_localizacao' => $loc,

                    'valido' => empty($erros),
                    'erros' => $erros ?: null,
                    'warnings' => $warnings ?: null,
                ]);

                if ($erros) {
                    $invalidos++;
                } else {
                    $validos++;
                }

                $linhaPlanilha++;
            }
        }

        $import->update([
            'linhas_total' => $validos + $invalidos,
            'linhas_validas' => $validos,
            'linhas_invalidas' => $invalidos,
            'metricas' => [
                'layout' => $detectedNovoLayout ? 'novo' : 'legado',
            ],
        ]);

        return $import;
    }

    public function processar(EstoqueImport $import, bool $dryRun = false): array
    {
        $import->update(['status' => 'processando', 'mensagem' => null]);

        $movCriadas = 0;
        $fornecedoresCriados = 0;
        $outletsCriados = 0;
        $produtosCriados = 0;
        $variacoesCriadas = 0;
        $registrosAtualizados = 0;

        $invalidRows = $import->rows()
            ->where('valido', false)
            ->orderBy('linha_planilha')
            ->get(['linha_planilha', 'erros']);

        $depositoJb = Deposito::firstOrCreate(['nome' => 'Depósito JB']);
        $depositoLoja = Deposito::firstOrCreate(['nome' => 'Loja']);

        $motivoPadrao = OutletMotivo::query()
            ->whereIn('slug', ['promocao_pontual', 'tempo_estoque'])
            ->orderByRaw("FIELD(slug, 'promocao_pontual', 'tempo_estoque')")
            ->first();

        if (!$motivoPadrao) {
            $motivoPadrao = OutletMotivo::create([
                'slug' => 'promocao_pontual',
                'nome' => 'Promocao pontual',
                'ativo' => true,
            ]);
        }

        $formaAvista = OutletFormaPagamento::firstOrCreate(
            ['slug' => 'avista'],
            [
                'nome' => 'À vista',
                'max_parcelas_default' => null,
                'percentual_desconto_default' => null,
                'ativo' => true,
            ]
        );

        // Agrupar por (cod + atributos + depÃ³sito efetivo + local efetivo + categoria)
        $grupos = $import->rows()
            ->where('valido', true)
            ->get()
            ->groupBy(function (EstoqueImportRow $r) {

                $depositoNome = $this->normalizarDeposito((string)$r->deposito);
                $statusNorm = $this->normalizarTexto((string)$r->status);

                $emEstoque = $depositoNome !== null && $this->isEmEstoque($statusNorm);

                $pd = is_array($r->parsed_dimensoes) ? $r->parsed_dimensoes : [];

                // fallback: se staging antigo nÃ£o tiver tampo_cor, tenta extrair do nome
                $tampoCor = $pd['tampo_cor'] ?? null;
                if (!$tampoCor && $r->nome) {
                    $np = $this->nomeAttrParser->extrair((string)$r->nome);
                    $tampoCor = $np['tampo_cor'] ?? null;
                }

                $attrs = [
                    'madeira' => $r->madeira,
                    'tecido_1' => $r->tecido_1,
                    'tecido_2' => $r->tecido_2,
                    'metal_vidro' => $r->metal_vidro,

                    // cores/acabamentos/tom madeira
                    'cor_primaria' => $pd['cor_primaria'] ?? null,
                    'cor_secundaria' => $pd['cor_secundaria'] ?? null,
                    'acabamentos' => $pd['acabamentos'] ?? null,
                    'tom_madeira' => $pd['tom_madeira'] ?? null,

                    // atributo do nome (resolve E65026: TAMPO BRANCO vs PRETO)
                    'tampo_cor' => $tampoCor,

                    // dimensÃµes extras tambÃ©m diferenciam variaÃ§Ãµes
                    'diametro_cm' => $pd['diam_cm'] ?? ($pd['diam_cm'] ?? null),
                    'comprimento_cm' => $pd['comp_cm'] ?? ($pd['comp_cm'] ?? null),
                    'espessura_cm' => $pd['esp_cm'] ?? ($pd['esp_cm'] ?? null),
                ];

                $fornecedorNome = trim((string)($pd['fornecedor'] ?? $r->cliente ?? ''));

                $attrsKey = md5(json_encode($attrs, JSON_UNESCAPED_UNICODE));

                $depKey = $emEstoque ? $depositoNome : 'NULL_DEP';
                $locKey = $emEstoque ? (string)($r->localizacao ?? 'NULL_LOC') : 'NULL_LOC';

                $codKey = (string)$r->cod;
                if ($codKey === '') {
                    $codKey = 'SEM_COD_' . md5($this->normalizarTexto((string)$r->nome));
                }

                return implode('|', [
                    $codKey,
                    $attrsKey,
                    $depKey,
                    $locKey,
                    (string)$r->categoria,
                    $fornecedorNome,
                ]);
            });

        DB::beginTransaction();
        try {
            foreach ($grupos as $linhas) {
                /** @var \Illuminate\Support\Collection<int,EstoqueImportRow> $linhas */
                $primeira = $linhas->first();
                $cod = (string)$primeira->cod;

                // Nome escolhido (maior)
                $nomeEscolhido = $linhas->pluck('nome')->sortByDesc(fn($n) => mb_strlen((string)$n))->first();
                $nomeEscolhido = (string)($nomeEscolhido ?? '');

                // DimensÃµes preferindo parsed_dimensoes do staging
                $dim = $this->pickDimensoes($linhas, $nomeEscolhido);

                // nome base preferido (staging novo), fallback para parser no nome completo
                $pd = is_array($primeira->parsed_dimensoes) ? $primeira->parsed_dimensoes : [];
                $nomeBaseProduto = trim((string)($pd['nome_base'] ?? ''));
                if ($nomeBaseProduto === '' && $nomeEscolhido !== '') {
                    $np = $this->nomeAttrParser->extrair($nomeEscolhido);
                    $nomeBaseProduto = trim((string)($np['nome_base'] ?? ''));
                }

                $nomeLimpo = $nomeBaseProduto !== '' ? $nomeBaseProduto : trim((string)($dim['clean'] ?? $nomeEscolhido));
                if ($nomeLimpo === '') $nomeLimpo = $nomeEscolhido ?: 'Produto';

                // Categoria
                $categoriaId = null;
                if ($primeira->categoria) {
                    $cat = Categoria::firstOrCreate(['nome' => trim((string)$primeira->categoria)]);
                    $categoriaId = $cat->id;
                }

                $fornecedorId = null;
                $fornecedorNome = trim((string)($pd['fornecedor'] ?? $primeira->cliente ?? ''));
                if ($fornecedorNome !== '') {
                    $fornecedor = Fornecedor::firstOrCreate(
                        ['nome' => $fornecedorNome],
                        ['status' => 1]
                    );
                    if ($fornecedor->wasRecentlyCreated) {
                        $fornecedoresCriados++;
                    }
                    $fornecedorId = $fornecedor->id;
                }

                $precoVenda = $this->toDecimal($pd['preco_venda'] ?? $primeira->valor ?? null);
                $precoCusto = $this->toDecimal($pd['preco_custo'] ?? null);

                // quantidade total (no staging jÃ¡ vira 0 quando nÃ£o em estoque)
                $qtdTotal = (int)$linhas->sum(fn($l) => (int)($l->qtd ?? 0));

                // tampo_cor (staging novo ou fallback parser)
                $tampoCor = $pd['tampo_cor'] ?? null;
                if (!$tampoCor && $nomeEscolhido !== '') {
                    $np = $this->nomeAttrParser->extrair($nomeEscolhido);
                    $tampoCor = $np['tampo_cor'] ?? null;
                }

                $attrs = [
                    'madeira'     => $primeira->madeira,
                    'tecido_1'    => $primeira->tecido_1,
                    'tecido_2'    => $primeira->tecido_2,
                    'metal_vidro' => $primeira->metal_vidro,

                    'cor_primaria'   => $pd['cor_primaria'] ?? null,
                    'cor_secundaria' => $pd['cor_secundaria'] ?? null,
                    'acabamentos'    => $pd['acabamentos'] ?? null,
                    'tom_madeira'    => $pd['tom_madeira'] ?? null,

                    'tampo_cor'      => $tampoCor,

                    'diametro_cm'    => $dim['diam_cm'] ?? ($dim['diam_cm'] ?? null),
                    'comprimento_cm' => $dim['comp_cm'] ?? ($dim['comp_cm'] ?? null),
                    'espessura_cm'   => $dim['esp_cm'] ?? ($dim['esp_cm'] ?? null),
                ];

                $atributosLimpos = $pd['atributos_limpos'] ?? [];
                if (is_array($atributosLimpos)) {
                    foreach ($atributosLimpos as $k => $v) {
                        if (!is_string($k) || trim($k) === '') {
                            continue;
                        }
                        $attrs[$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                }

                // Upsert produto/variaÃ§Ã£o/atributos
                $up = $this->produtoUpsert->upsertProdutoVariacao([
                    'nome_limpo'    => $nomeLimpo,
                    'nome_completo' => $nomeEscolhido,
                    'categoria_id'  => $categoriaId,
                    'fornecedor_id' => $fornecedorId,

                    'w_cm' => $dim['w_cm'] ?? null,
                    'p_cm' => $dim['p_cm'] ?? null,
                    'a_cm' => $dim['a_cm'] ?? null,

                    'valor' => $precoVenda,
                    'custo' => $precoCusto,
                    'cod'   => $cod,
                    'atributos' => $attrs,
                ]);

                $variacao = $up['variacao'];
                if (!empty($up['produto_criado'])) {
                    $produtosCriados++;
                }
                if (!empty($up['variacao_criada'])) {
                    $variacoesCriadas++;
                } else {
                    $registrosAtualizados++;
                }

                $depositoMov = $this->resolverDepositoPorStatus((string)$primeira->status);
                if (!$depositoMov) {
                    $layoutNovoRow = (bool)($pd['layout_novo'] ?? false);
                    if (!$layoutNovoRow) {
                        $depLegacy = $this->normalizarDeposito((string)$primeira->deposito);
                        $statusNorm = $this->normalizarTexto((string)$primeira->status);
                        if ($depLegacy !== null && $this->isEmEstoque($statusNorm)) {
                            $depositoMov = $depLegacy;
                        }
                    }
                }

                $qtdTotal = max(0, (int)$qtdTotal);
                if ($depositoMov !== null) {
                    $depositoId = match ($depositoMov) {
                        'Depósito JB' => (int)$depositoJb->id,
                        'Loja' => (int)$depositoLoja->id,
                        default => (int) Deposito::firstOrCreate(['nome' => $depositoMov])->id,
                    };

                    $estoque = Estoque::firstOrCreate(
                        ['id_variacao' => $variacao->id, 'id_deposito' => $depositoId],
                        ['quantidade' => 0]
                    );

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

                    if ($qtdTotal > 0 && !$dryRun) {
                        $dataMov = $this->toDateFlexible($pd['data_entrada'] ?? $primeira->data);
                        $observacoes = ["Importacao de estoque via planilha #{$import->id}"];
                        if (!$dataMov) {
                            $dataMov = now();
                            $observacoes[] = 'Data de entrada nao informada; usada data atual.';
                        }
                        if (!empty($primeira->localizacao)) {
                            $observacoes[] = 'Localizacao: ' . $primeira->localizacao;
                        }

                        app(EstoqueMovimentacaoService::class)->registrarMovimentacaoManual([
                            'id_variacao'         => (int)$variacao->id,
                            'id_deposito_origem'  => null,
                            'id_deposito_destino' => $depositoId,
                            'tipo'                => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                            'quantidade'          => $qtdTotal,
                            'observacao'          => implode(' | ', $observacoes),
                            'data_movimentacao'   => $dataMov,
                        ], $import->usuario_id);

                        $movCriadas++;
                    }
                }

                $outletMarcado = $this->toBool($pd['outlet'] ?? false);
                if ($outletMarcado) {
                    $qtdOutlet = max(1, $qtdTotal);

                    $outlet = ProdutoVariacaoOutlet::query()
                        ->where('produto_variacao_id', $variacao->id)
                        ->where('quantidade_restante', '>', 0)
                        ->latest('id')
                        ->first();

                    if (!$outlet) {
                        $outlet = ProdutoVariacaoOutlet::create([
                            'produto_variacao_id' => $variacao->id,
                            'motivo_id' => $motivoPadrao->id,
                            'quantidade' => $qtdOutlet,
                            'quantidade_restante' => $qtdOutlet,
                            'usuario_id' => $import->usuario_id,
                        ]);
                        $outletsCriados++;
                    }

                    ProdutoVariacaoOutletPagamento::updateOrCreate(
                        [
                            'produto_variacao_outlet_id' => $outlet->id,
                            'forma_pagamento_id' => $formaAvista->id,
                        ],
                        [
                            'percentual_desconto' => 50,
                            'max_parcelas' => null,
                        ]
                    );
                }
            }

            $resumo = [
                'dry_run' => $dryRun,
                'total_linhas' => (int)$import->linhas_total,
                'linhas_validas' => (int)$import->linhas_validas,
                'linhas_invalidas' => (int)$import->linhas_invalidas,
                'produtos_criados' => $produtosCriados,
                'variacoes_criadas' => $variacoesCriadas,
                'registros_atualizados' => $registrosAtualizados,
                'movimentacoes_criadas' => $movCriadas,
                'outlets_criados' => $outletsCriados,
                'fornecedores_criados' => $fornecedoresCriados,
                'grupos_processados' => $grupos->count(),
            ];

            if ($dryRun) {
                DB::rollBack();
                $import->update([
                    'status' => 'concluido',
                    'linhas_processadas' => $import->linhas_validas,
                    'mensagem' => 'Dry run: nenhuma alteração foi persistida.',
                    'metricas' => $resumo,
                ]);
            } else {
                DB::commit();
                $import->update([
                    'status' => 'concluido',
                    'linhas_processadas' => $import->linhas_validas,
                    'metricas' => $resumo,
                ]);
            }

            return [
                'sucesso' => true,
                'dry_run' => $dryRun,
                'resumo' => $resumo,
                'erros' => $invalidRows->map(fn ($r) => [
                    'linha' => (int) $r->linha_planilha,
                    'erros' => is_array($r->erros) ? $r->erros : [],
                ])->values()->all(),
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

    private function shouldSkipSheet(string $name): bool
    {
        $n = $this->normalizarTexto($name);

        if (Str::contains($n, 'dicionario')) return true;
        if (Str::contains($n, 'resumo')) return true;
        if (Str::contains($n, 'auditoria')) return true;

        return false;
    }

    private function headerHasMinimum(array $headerMap): bool
    {
        return (isset($headerMap['nome']) || isset($headerMap['nome_normalizado']))
            && isset($headerMap['qd']);
    }

    private function isNovoLayoutHeader(array $headerMap): bool
    {
        return isset($headerMap['fornecedor'])
            || isset($headerMap['preco_custo'])
            || isset($headerMap['preco_venda'])
            || isset($headerMap['outlet'])
            || isset($headerMap['data_entrada'])
            || isset($headerMap['atributos_limpos'])
            || isset($headerMap['nome_produto']);
    }

    private function defaultDepositoFromSheet(string $sheetName): ?string
    {
        $n = $this->normalizarTexto($sheetName);

        if (Str::contains($n, 'loja')) return 'Loja';
        if (Str::contains($n, 'deposito jb')) return 'Depósito JB';
        if (Str::contains($n, 'deposito')) return 'Depósito';

        if (Str::contains($n, 'adornos')) return 'Loja';

        return null;
    }

    private function isRowEmpty(array $row): bool
    {
        $keys = [
            'codigo', 'nome', 'nome_produto', 'nome_normalizado', 'categoria',
            'fornecedor', 'preco_custo', 'preco_venda', 'outlet', 'data_entrada', 'atributos_limpos',
            'deposito', 'status', 'qd', 'madeira', 'tecido_1', 'tecido_2', 'metal_vidro',
            'largura', 'altura', 'profundidade', 'diametro', 'comprimento', 'espessura',
            'cor_primaria', 'cor_secundaria', 'acabamentos', 'tom_madeira',
            'localizacao', 'setor', 'coluna', 'nivel', 'area',
        ];

        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) continue;
            $v = $row[$k];
            if ($v === null) continue;
            if (is_string($v) && trim($v) === '') continue;
            return false;
        }

        return true;
    }

    private function mapHeaderNormalizado(array $headerRow): array
    {
        $map = [];

        $aliases = [
            // quantidade
            'qd' => 'qd',
            'qtd' => 'qd',
            'quantidade' => 'qd',
            'unidade' => 'qd',

            // cÃ³digo
            'codigo' => 'codigo',
            'cÃ³digo' => 'codigo',
            'cod' => 'codigo',
            'referencia' => 'codigo',
            'referÃªncia' => 'codigo',

            // depÃ³sito
            'deposito' => 'deposito',
            'depÃ³sito' => 'deposito',

            // status
            'status' => 'status',

            // nome
            'nome' => 'nome',
            'nome produto' => 'nome',
            'nome_produto' => 'nome',
            'nome_normalizado' => 'nome_normalizado',
            'nome normalizado' => 'nome_normalizado',

            // categoria
            'categoria' => 'categoria',
            'fornecedor' => 'fornecedor',

            // valores / outlet
            'preco_custo' => 'preco_custo',
            'preco custo' => 'preco_custo',
            'preÃ§o custo' => 'preco_custo',
            'preco_venda' => 'preco_venda',
            'preco venda' => 'preco_venda',
            'preÃ§o venda' => 'preco_venda',
            'outlet' => 'outlet',
            'data_entrada' => 'data_entrada',
            'data entrada' => 'data_entrada',
            'atributos_limpos' => 'atributos_limpos',
            'atributos limpos' => 'atributos_limpos',

            // materiais
            'madeira' => 'madeira',
            'tecidos' => 'tecido_1',
            'tecido' => 'tecido_1',
            'tec. 1' => 'tecido_1',
            'tec 1' => 'tecido_1',
            'tecido 1' => 'tecido_1',
            'tec. 2' => 'tecido_2',
            'tec 2' => 'tecido_2',
            'tecido 2' => 'tecido_2',

            'metal / vidro' => 'metal_vidro',
            'metal/vidro' => 'metal_vidro',
            'metal vidro' => 'metal_vidro',
            'metal' => 'metal_vidro',

            // localizaÃ§Ã£o
            'localizacao' => 'localizacao',
            'localizaÃ§Ã£o' => 'localizacao',
            'setor' => 'setor',
            'coluna' => 'coluna',
            'nivel' => 'nivel',
            'nÃ­vel' => 'nivel',
            'area' => 'area',
            'Ã¡rea' => 'area',

            // dimensÃµes
            'largura' => 'largura',
            'altura' => 'altura',
            'profundidade' => 'profundidade',

            'largura_cm' => 'largura',
            'altura_cm' => 'altura',
            'profundidade_cm' => 'profundidade',
            'diametro_cm' => 'diametro',
            'diÃ¢metro_cm' => 'diametro',
            'diametro' => 'diametro',
            'diÃ¢metro' => 'diametro',

            'comprimento_cm' => 'comprimento',
            'comprimento' => 'comprimento',
            'espessura_cm' => 'espessura',
            'espessura' => 'espessura',

            // cores/acabamentos
            'cor_primaria' => 'cor_primaria',
            'cor primÃ¡ria' => 'cor_primaria',
            'cor primaria' => 'cor_primaria',
            'cor_secundaria' => 'cor_secundaria',
            'cor secundÃ¡ria' => 'cor_secundaria',
            'cor secundaria' => 'cor_secundaria',

            'acabamentos_detectados' => 'acabamentos',
            'acabamentos detectados' => 'acabamentos',
            'acabamento' => 'acabamentos',
            'acabamentos' => 'acabamentos',

            'tom_madeira_detectado' => 'tom_madeira',
            'tom madeira detectado' => 'tom_madeira',
            'tom madeira' => 'tom_madeira',
        ];

        foreach ($headerRow as $col => $name) {
            $raw = trim((string)$name);
            if ($raw === '') continue;

            $key = $this->normalizarTexto($raw);
            $key = str_replace('_', ' ', $key);
            $key = preg_replace('/\s+/', ' ', $key);
            $key = trim($key, " ,;:\t\n\r\0\x0B");

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

        if (Str::contains($n, 'deposito jb')) return 'Depósito JB';
        if (Str::contains($n, 'loja')) return 'Loja';
        if (Str::contains($n, 'deposito')) return 'Depósito';

        return null;
    }

    private function resolverDepositoPorStatus(?string $status): ?string
    {
        $s = $this->normalizarTexto((string)$status);
        if ($s === '') {
            return null;
        }

        if (Str::contains($s, 'deposito jb')) {
            return 'Depósito JB';
        }

        if ($s === 'loja' || Str::contains($s, ' loja')) {
            return 'Loja';
        }

        return null;
    }

    private function isEmEstoque(string $statusNorm): bool
    {
        $s = $this->normalizarTexto($statusNorm);
        if ($s === '') return false;

        // negativos Ã³bvios
        if (Str::contains($s, ['vend', 'baixa', 'reserv', 'entreg', 'retir'])) {
            return false;
        }

        // positivos (inclui status reais da planilha)
        if (in_array($s, ['em estoque', 'estoque', 'disponivel', 'loja', 'deposito'], true)) return true;
        if (Str::contains($s, ['em estoque', 'estoque', 'disponivel', 'loja', 'deposito'])) return true;

        return false;
    }

    private function buildLocalizacaoFromColumns(
        string $setorRaw,
        string $colunaRaw,
        ?int $nivel,
        string $areaRaw,
        string $legacy
    ): array {
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
                    ($d['diam_cm'] ?? null) !== null ||
                    ($d['comp_cm'] ?? null) !== null ||
                    ($d['esp_cm'] ?? null) !== null ||
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

        if (str_contains($s, '.') && str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',')) {
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

        if (is_string($v)) {
            $d = $this->toDecimal($v);
            return $d === null ? null : (int)$d;
        }

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
            if (floor($v) == $v) return (string)(int)$v;
            $s = rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
            return $s;
        }

        return trim((string)$v);
    }

    private function toDateFlexible(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }

        try {
            if ($v instanceof DateTimeInterface) {
                return Carbon::instance(\DateTime::createFromInterface($v))->startOfDay();
            }

            if (is_int($v) || is_float($v)) {
                $dt = SpreadsheetDate::excelToDateTimeObject($v);
                return Carbon::instance($dt)->startOfDay();
            }

            $s = trim((string)$v);
            if ($s === '') {
                return null;
            }

            foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $fmt) {
                if (!Carbon::hasFormatWithModifiers($s, $fmt)) {
                    continue;
                }

                $dt = Carbon::createFromFormat($fmt, $s, config('app.timezone'));
                if ($dt !== false) {
                    return $dt->startOfDay();
                }
            }

            return Carbon::parse($s, config('app.timezone'))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) return false;
        if (is_string($value)) return trim($value) !== '';
        return true;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = $this->normalizarTexto((string)$value);
        if ($raw === '') {
            return false;
        }

        return in_array($raw, ['1', 'true', 'sim', 's', 'yes', 'y', 'outlet'], true);
    }

    private function parseAtributosLimpos(mixed $value): array
    {
        $raw = $this->toText($value);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $out = [];
            foreach ($decoded as $k => $v) {
                if (!is_scalar($k) || $k === '') {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $out[(string)$k] = $v === null ? '' : (string)$v;
                }
            }
            return $out;
        }

        $parts = preg_split('/[;\n]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, ':')) {
                [$k, $v] = array_map('trim', explode(':', $part, 2));
                if ($k !== '') {
                    $out[$k] = $v;
                }
            } else {
                $out['atributos_limpos_texto'] = $part;
            }
        }

        return $out;
    }
}

