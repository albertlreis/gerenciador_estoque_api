<?php

namespace App\Services\Import;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\AreaEstoque;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueImport;
use App\Models\EstoqueImportRow;
use App\Models\EstoqueMovimentacao;
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
        private readonly LocalizacaoParser $locParser,
        private readonly DimensoesParser $dimParser,
        private readonly ProdutoUpsertService $produtoUpsert,
    ) {}

    /** Cria o registro de import (staging) e explode as linhas (não processa ainda). */
    public function criarStaging(UploadedFile $arquivo, ?int $usuarioId = null): EstoqueImport
    {
        $hash = hash_file('sha256', $arquivo->getRealPath());
        $import = EstoqueImport::create([
            'arquivo_nome' => $arquivo->getClientOriginalName(),
            'arquivo_hash' => $hash,
            'usuario_id'   => $usuarioId,
            'status'       => 'pendente',
        ]);

        // Ler Excel (aba única "Depósito")
        $spreadsheet = IOFactory::load($arquivo->getRealPath());
        $sheet = $spreadsheet->getSheetByName('Depósito') ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // Cabeçalhos esperados
        // Qtd, Data NF, Cod, Localização, Nome, Categoria, Madeira, Tec. 1, Tec. 2, Metal / Vidro, Valor, FL, Depósito, Data, Cliente
        $headerMap = $this->mapHeader(array_shift($rows));

        $linhaNum = 2;
        $validos = $invalidos = 0;

        foreach ($rows as $r) {
            $row = $this->rowToAssoc($r, $headerMap);

            // Higiene básica: trim em todas as strings
            $cod  = trim((string)($row['Cod'] ?? ''));
            $nomeOriginal = trim((string)($row['Nome'] ?? ''));
            $categoria = trim((string)($row['Categoria'] ?? ''));
            $madeira = trim((string)($row['Madeira'] ?? ''));
            $tec1 = trim((string)($row['Tec. 1'] ?? ''));
            $tec2 = trim((string)($row['Tec. 2'] ?? ''));
            $metalVidro = trim((string)($row['Metal / Vidro'] ?? ''));
            $localizacao = trim((string)($row['Localização'] ?? ''));
            $depositoRaw = trim((string)($row['Depósito'] ?? ''));
            // Ignorados: Cliente, Valor, Data NF, Data, FL
            $cliente = trim((string)($row['Cliente'] ?? ''));
            $valor = $this->toDecimal($row['Valor'] ?? null);
            $qtd = $this->toInt($row['Qtd'] ?? null);
            $dataNF = $this->toDate($row['Data NF'] ?? null);
            $data = $this->toDate($row['Data'] ?? null);

            // Extrai dimensões — o parser retorna 'clean' e 'raw'
            $dim = $this->dimParser->extrair($nomeOriginal);

            // Localização parseada
            $loc = $this->locParser->parse($localizacao);

            $erros = [];
//            if ($cod === '') $erros[] = 'Cod vazio';
            if ($qtd === null || $qtd < 0) $erros[] = 'Qtd inválida';

            $hashLinha = hash('sha256', json_encode([$import->arquivo_hash, $linhaNum, $cod, $nomeOriginal], JSON_UNESCAPED_UNICODE));

            EstoqueImportRow::create([
                'import_id' => $import->id,
                'linha_planilha' => $linhaNum,
                'hash_linha' => $hashLinha,
                'cod' => $cod,
                // Mantemos 'nome' como o nome original (para audit/troubleshooting).
                // parsed_dimensoes contém 'clean' (nome antes das medidas) e 'raw' (texto das medidas).
                'nome' => $nomeOriginal,
                'categoria' => $categoria,
                'madeira' => $madeira !== '' ? $madeira : null,
                'tecido_1' => $tec1 !== '' ? $tec1 : null,
                'tecido_2' => $tec2 !== '' ? $tec2 : null,
                'metal_vidro' => $metalVidro !== '' ? $metalVidro : null,
                'localizacao' => $localizacao !== '' ? $localizacao : null,
                'deposito' => $depositoRaw !== '' ? $depositoRaw : null,
                'cliente' => $cliente !== '' ? $cliente : null,
                'data_nf' => null, // ignoramos Data NF no staging/processing conforme regra
                'data' => null,
                'valor' => null,
                'qtd' => $qtd,
                'parsed_dimensoes' => $dim,
                'parsed_localizacao' => $loc,
                'valido' => empty($erros),
                'erros' => $erros ?: null,
                'warnings' => null,
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

    /**
     * Processa a importação (com ou sem dry-run).
     * Regras importantes aplicadas:
     *  - Ignora colunas Valor, Data NF, FL, Data e Cliente para efeitos de movimentação/pedidos
     *  - Depósitos oficiais somente "Depósito JB" e "Loja" (case-insensitive, sem acento)
     *  - Produtos com qtd == 0: cadastro de produto/variação/atributos, sem movimentação (estoque criado com 0 apenas se depósito oficial)
     *  - Depósitos não oficiais: cadastra produto/variação/atributos, NÃO cria estoque nem movimentação
     */
    public function processar(EstoqueImport $import, bool $dryRun = false): array
    {
        $import->update(['status' => 'processando', 'mensagem' => null]);

        $novos = $atualizados = $rejeitados = 0;
        $pfCriados = $pfItens = 0;
        $movCriadas = 0;

        // Agrupar por chave (Cod + atributos + depósito efetivo), somando Qtd
        $grupos = $import->rows()
            ->where('valido', true)
            ->get()
            ->groupBy(function(EstoqueImportRow $r) {
                $depRaw = (string)$r->deposito;
                $depNorm = Str::of($depRaw)->trim()->lower()->ascii()->__toString();

                // Consideramos depósito oficial somente quando contém 'jb' (Depósito JB) ou é 'loja'
                $depositoOficial = false;
                $depositoEfetivoNome = null;
                if ($depNorm !== '') {
                    if (Str::contains($depNorm, 'jb') || $depNorm === 'loja') {
                        $depositoOficial = true;
                        // Normalizar nomes oficiais
                        if ($depNorm === 'loja' || $depNorm === 'loja') {
                            $depositoEfetivoNome = 'Loja';
                        } else {
                            $depositoEfetivoNome = 'Depósito JB';
                        }
                    }
                }

                $attrs = [
                    'madeira' => $r->madeira,
                    'tecido_1' => $r->tecido_1,
                    'tecido_2' => $r->tecido_2,
                    'metal_vidro' => $r->metal_vidro,
                ];
                $attrsKey = md5(json_encode($attrs, JSON_UNESCAPED_UNICODE));

                // Se depósito não é oficial, usar valor nulo na chave para evitar que depósito "xpto" crie estoque/movimentação
                $depKey = $depositoOficial ? $depositoEfetivoNome : 'NULL_DEP';

                return implode('|', [
                    (string)$r->cod,
                    $attrsKey,
                    $depKey,
                    (string)$r->localizacao,
                    (string)$r->categoria,
                ]);
            });

        DB::beginTransaction();
        try {
            foreach ($grupos as $key => $linhas) {
                $primeira = $linhas->first();
                $cod = $primeira->cod;

                // Nome mais longo (usamos o nome original salvo na staging)
                $nomeEscolhido = $linhas->pluck('nome')->sortByDesc(fn($n)=>mb_strlen((string)$n))->first();
                $dim = $this->dimParser->extrair((string)$nomeEscolhido);
                // Nome limpo conforme parser: texto ANTES das medidas, sem Ø, sem sufixo CM, sem espaços extras
                $nomeLimpo = trim($dim['clean'] ?? (string)$nomeEscolhido);

                // Categoria (upsert simples por nome)
                $categoriaId = null;
                if ($primeira->categoria) {
                    $cat = Categoria::firstOrCreate(['nome' => trim($primeira->categoria)]);
                    $categoriaId = $cat->id;
                }

                // Somar quantidade e escolher valor (ignoramos valor conforme regra)
                $qtdTotal = (int) $linhas->sum(fn($l)=> (int)($l->qtd ?? 0));

                // Upsert Produto + Variação + Atributos
                $attrs = [
                    'madeira'   => $primeira->madeira,
                    'tecido_1'  => $primeira->tecido_1,
                    'tecido_2'  => $primeira->tecido_2,
                    'metal_vidro' => $primeira->metal_vidro,
                ];

                $up = $this->produtoUpsert->upsertProdutoVariacao([
                    'nome_limpo'   => $nomeLimpo,
                    'nome_completo'=> $nomeEscolhido,
                    'categoria_id' => $categoriaId,
                    'w_cm' => $dim['w_cm'] ?? null,
                    'p_cm' => $dim['p_cm'] ?? null,
                    'a_cm' => $dim['a_cm'] ?? null,
                    'valor' => null, // valor ignorado na regra
                    'cod'   => $cod,
                    'atributos' => $attrs,
                ]);

                $variacao = $up['variacao'];

                // Determinar depósito efetivo (reaplicar lógica de oficialidade)
                $depRaw = (string)$primeira->deposito;
                $depNorm = Str::of($depRaw)->trim()->lower()->ascii()->__toString();

                $depositoOficial = false;
                $depositoNomeNormalizado = null;
                if ($depNorm !== '') {
                    if (Str::contains($depNorm, 'jb') || $depNorm === 'loja') {
                        $depositoOficial = true;
                        $depositoNomeNormalizado = Str::contains($depNorm, 'jb') ? 'Depósito JB' : 'Loja';
                    }
                }

                // Localização parseada (somente coluna Localização)
                $locParsed = $this->locParser->parse($primeira->localizacao);

                // Criar/Atualizar ESTOQUE + LOCALIZAÇÃO + MOVIMENTAÇÃO ENTRADA_DEPOSITO
                $estoque = null;
                $depositoModel = null;

                if ($depositoOficial) {
                    // cria/recupera deposito oficial
                    $depositoModel = Deposito::firstOrCreate(['nome' => $depositoNomeNormalizado]);

                    // Estoque: criar registro com quantidade atual (firstOrCreate com quantidade 0)
                    $estoque = Estoque::firstOrCreate(
                        ['id_variacao' => $variacao->id, 'id_deposito' => $depositoModel->id],
                        ['quantidade' => 0]
                    );

                    // Localização (por estoque)
                    if ($locParsed['tipo'] === 'posicao' || $locParsed['tipo'] === 'area') {
                        // Area opcional
                        $areaId = null;
                        if ($locParsed['tipo'] === 'area' && $locParsed['area']) {
                            $area = AreaEstoque::firstOrCreate(['nome' => mb_convert_case($locParsed['area'], MB_CASE_TITLE, 'UTF-8')]);
                            $areaId = $area->id;
                        }

                        $estoque->localizacao()->updateOrCreate(
                            ['estoque_id' => $estoque->id],
                            [
                                'setor' => $locParsed['setor'],
                                'coluna' => $locParsed['coluna'],
                                'nivel' => $locParsed['nivel'],
                                'area_id' => $areaId,
                                'codigo_composto' => $locParsed['codigo'],
                            ]
                        );
                    }
                }

                // Movimentação de entrada para fixar estoque inicial
                // Regra: só criar movimentação se qtdTotal > 0 e depósito for oficial
                if ($qtdTotal > 0 && $depositoOficial) {
                    if (!$dryRun) {
                        EstoqueMovimentacao::create([
                            'id_variacao' => $variacao->id,
                            'id_deposito_origem' => null,
                            'id_deposito_destino' => $depositoModel->id,
                            'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                            'quantidade' => $qtdTotal,
                            'observacao' => "Importação inicial #{$import->id}",
                            'data_movimentacao' => now(),
                            'id_usuario' => $import->usuario_id,
                        ]);
                        // Atualiza quantidade do estoque
                        $estoque->increment('quantidade', $qtdTotal);
                        $movCriadas++;
                    }
                }

                // contadores simples (poderíamos incrementar novos/atualizados com base em retorno do produtoUpsert)
                $novos++; // aproximação; ajustar conforme desejado
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
                    'pf_criados' => $pfCriados,
                    'pf_itens' => $pfItens,
                    'grupos_processados' => $grupos->count(),
                ],
            ]);

            return [
                'sucesso' => true,
                'dry_run' => $dryRun,
                'movimentacoes_criadas' => $movCriadas,
                'pf_criados' => $pfCriados,
                'pf_itens' => $pfItens,
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

    /** Mapear cabeçalho por nome exato das colunas da planilha. */
    private function mapHeader(array $headerRow): array
    {
        // $headerRow vem com chaves A,B,C...
        $map = [];
        foreach ($headerRow as $col => $name) {
            $map[trim((string)$name)] = $col;
        }
        return $map;
    }

    /** Converte row A,B,C... em assoc pelo cabeçalho esperado. */
    private function rowToAssoc(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $name => $col) {
            $out[$name] = $row[$col] ?? null;
        }
        return $out;
    }

    private function toDecimal(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        $s = str_replace(['.', ','], ['', '.'], (string)$v);
        return is_numeric($s) ? (float)$s : null;
    }

    private function toInt(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
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
