<?php

namespace App\Services;

use App\Enums\StatusRevisaoCadastro;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoCodigoHistorico;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProdutoVariacaoService
{
    private const CAMPOS_AUDITAVEIS = [
        'referencia',
        'nome',
        'preco',
        'custo',
        'codigo_barras',
        'sku_interno',
        'chave_variacao',
        'dimensao_1',
        'dimensao_2',
        'dimensao_3',
        'cor',
        'lado',
        'material_oficial',
        'acabamento_oficial',
        'conflito_codigo',
        'status_revisao',
    ];

    public function __construct(
        private readonly ContaAzulExportDispatchService $contaAzulExports,
        private readonly AuditoriaEventoService $auditoria,
    ) {
    }

    public function obterVariacaoCompleta(int $produtoId, int $variacaoId): Builder|array|Collection|Model
    {
        $variacao = ProdutoVariacao::with([
            'produto',
            'atributos',
            'codigosHistoricos',
            'imagem',
            'estoque',
            'estoques',
            'outlets',
            'outlets.motivo',
            'outlets.formasPagamento.formaPagamento',
        ])->findOrFail($variacaoId);

        if ($variacao->produto_id !== $produtoId) {
            abort(404, 'Variação não pertence a este produto.');
        }

        return $variacao;
    }

    public function criarParaProduto(Produto $produto, array $data): ProdutoVariacao
    {
        $this->makeValidator($data)->validate();

        $payload = $this->prepararPayloadParaPersistencia($data, $produto->id);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            ...$payload,
        ]);

        $this->sincronizarAtributos($variacao, $data['atributos'] ?? null);
        $this->sincronizarCodigosHistoricos(
            $variacao,
            $this->extrairCodigosHistoricos($data),
            array_key_exists('codigos_historicos', $data)
        );

        $variacao = $variacao->refresh()->load('atributos', 'codigosHistoricos', 'imagem');
        $this->contaAzulExports->produto((int) $produto->id, (int) $variacao->id, null, ['evento' => 'variacao_criada']);

        return $variacao;
    }

    public function atualizarLote(int $produtoId, array $variacoes): void
    {
        $produto = Produto::findOrFail($produtoId);
        $idsRecebidos = [];

        foreach ($variacoes as $variacaoData) {
            $variacaoExistente = !empty($variacaoData['id'])
                ? ProdutoVariacao::where('produto_id', $produto->id)->find($variacaoData['id'])
                : null;

            $this->makeValidator($variacaoData, $variacaoExistente)->validate();
            if ($variacaoExistente) {
                $this->validarMotivoAlteracaoPreco($variacaoExistente, $variacaoData);
            }

            $payload = $this->prepararPayloadParaPersistencia(
                $variacaoData,
                $produtoId,
                $variacaoExistente,
                parcial: $variacaoExistente !== null
            );

            if ($variacaoExistente) {
                $variacao = $this->salvarComAuditoria(
                    $variacaoExistente,
                    $payload,
                    (array) ($variacaoData['audit'] ?? []),
                    'Atualização de variação'
                );
            } else {
                $variacao = ProdutoVariacao::create([
                    'produto_id' => $produtoId,
                    ...$payload,
                ]);
            }

            $idsRecebidos[] = $variacao->id;

            if (array_key_exists('atributos', $variacaoData)) {
                $this->sincronizarAtributos($variacao, $variacaoData['atributos'] ?? []);
            }

            if (array_key_exists('codigos_historicos', $variacaoData)
                || $this->payloadTemCodigoHistoricoTopLevel($variacaoData)
            ) {
                $this->sincronizarCodigosHistoricos(
                    $variacao,
                    $this->extrairCodigosHistoricos($variacaoData),
                    array_key_exists('codigos_historicos', $variacaoData)
                );
            }

            $this->contaAzulExports->produto((int) $produto->id, (int) $variacao->id, null, ['evento' => 'variacao_atualizada_lote']);
        }

        $produto->variacoes()->whereNotIn('id', $idsRecebidos)->delete();
    }

    public function atualizarIndividual(ProdutoVariacao $variacao, array $data): ProdutoVariacao
    {
        $this->makeValidator($data, $variacao)->validate();
        $this->validarMotivoAlteracaoPreco($variacao, $data);

        $updates = $this->prepararPayloadParaPersistencia(
            $data,
            $variacao->produto_id,
            $variacao,
            parcial: true
        );

        $variacao = $this->salvarComAuditoria(
            $variacao,
            $updates,
            (array) ($data['audit'] ?? []),
            'Atualização de variação'
        );

        if (array_key_exists('atributos', $data)) {
            $this->sincronizarAtributos($variacao, $data['atributos'] ?? []);
        }

        if (array_key_exists('codigos_historicos', $data) || $this->payloadTemCodigoHistoricoTopLevel($data)) {
            $this->sincronizarCodigosHistoricos(
                $variacao,
                $this->extrairCodigosHistoricos($data),
                array_key_exists('codigos_historicos', $data)
            );
        }

        $variacao = $variacao->refresh()->load('atributos', 'codigosHistoricos', 'imagem');
        $this->contaAzulExports->produto((int) $variacao->produto_id, (int) $variacao->id, null, ['evento' => 'variacao_atualizada']);

        return $variacao;
    }

    public function gerarReferenciaLegadaFallback(array $data, ?ProdutoVariacao $variacao = null, ?int $produtoId = null): string
    {
        $referencia = trim((string) ($data['referencia'] ?? ''));
        if ($referencia !== '') {
            return Str::limit($referencia, 100, '');
        }

        if ($variacao && trim((string) $variacao->referencia) !== '') {
            return (string) $variacao->referencia;
        }

        $skuInterno = trim((string) ($data['sku_interno'] ?? ''));
        if ($skuInterno !== '') {
            return Str::limit('LEG-' . $skuInterno, 100, '');
        }

        $chaveVariacao = trim((string) ($data['chave_variacao'] ?? ''));
        if ($chaveVariacao !== '') {
            return 'LEG-' . substr(sha1($chaveVariacao), 0, 24);
        }

        $codes = $this->extrairCodigosHistoricos($data);
        if (!empty($codes)) {
            $seed = json_encode($codes[0], JSON_UNESCAPED_UNICODE);
            return 'LEG-' . substr(sha1((string) $seed), 0, 24);
        }

        $seed = json_encode([
            'produto_id' => $produtoId ?? $variacao?->produto_id,
            'nome' => $data['nome'] ?? $variacao?->nome,
            'preco' => $data['preco'] ?? $variacao?->preco,
            'custo' => $data['custo'] ?? $variacao?->custo,
            'timestamp' => $variacao?->id ?? microtime(true),
        ], JSON_UNESCAPED_UNICODE);

        return 'LEG-' . substr(sha1((string) $seed), 0, 24);
    }

    public function salvarComAuditoria(
        ProdutoVariacao $variacao,
        array $updates,
        array $auditInput = [],
        string $defaultLabel = 'Atualização de variação'
    ): ProdutoVariacao {
        if (empty($updates)) {
            return $variacao->fresh() ?? $variacao;
        }

        $before = $variacao->only(self::CAMPOS_AUDITAVEIS);
        $variacao->fill($updates);

        if (!$variacao->isDirty()) {
            return $variacao->fresh() ?? $variacao;
        }

        DB::transaction(function () use ($variacao, $before, $auditInput, $defaultLabel): void {
            $variacao->save();

            if ($variacao->wasChanged('preco')) {
                $this->sincronizarPrecoCarrinhosRascunho($variacao);
            }

            $mudancas = [];
            foreach (self::CAMPOS_AUDITAVEIS as $campo) {
                if (!$variacao->wasChanged($campo)) {
                    continue;
                }

                $mudancas[] = [
                    'campo' => $campo,
                    'old' => $before[$campo] ?? null,
                    'new' => $variacao->{$campo},
                ];
            }

            $metadataExtra = (array) ($auditInput['metadata'] ?? []);
            $metadata = array_filter([
                'motivo' => $auditInput['motivo'] ?? null,
                'origin' => $auditInput['origin'] ?? null,
                'carrinho_id' => $metadataExtra['carrinho_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $this->auditoria->registrar(
                module: 'produto_variacoes',
                action: 'update',
                label: (string) ($auditInput['label'] ?? $defaultLabel),
                auditable: $variacao,
                mudancas: $mudancas,
                metadata: $metadata
            );
        });

        return $variacao->refresh();
    }

    public function validarMotivoAlteracaoPreco(ProdutoVariacao $variacao, array $data): void
    {
        if (!$this->precoMudou($variacao, $data)) {
            return;
        }

        $motivo = trim((string) data_get($data, 'audit.motivo', ''));
        if ($motivo === '') {
            throw ValidationException::withMessages([
                'audit.motivo' => 'Informe o motivo da alteração de preço.',
            ]);
        }
    }

    private function makeValidator(array $data, ?ProdutoVariacao $variacao = null): ValidatorContract
    {
        $ignoreId = $variacao?->id ?? ($data['id'] ?? null);

        return Validator::make($data, [
            'id' => 'nullable|integer|exists:produto_variacoes,id',
            'preco' => 'required|numeric|min:0',
            'custo' => 'nullable|numeric|min:0',
            'referencia' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('produto_variacoes', 'referencia')->ignore($ignoreId),
            ],
            'sku_interno' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('produto_variacoes', 'sku_interno')->ignore($ignoreId),
            ],
            'chave_variacao' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('produto_variacoes', 'chave_variacao')->ignore($ignoreId),
            ],
            'codigo_barras' => 'nullable|string|max:255',
            'dimensao_1' => 'nullable|numeric|min:0',
            'dimensao_2' => 'nullable|numeric|min:0',
            'dimensao_3' => 'nullable|numeric|min:0',
            'cor' => 'nullable|string|max:150',
            'lado' => 'nullable|string|max:120',
            'material_oficial' => 'nullable|string|max:180',
            'acabamento_oficial' => 'nullable|string|max:180',
            'conflito_codigo' => 'nullable|boolean',
            'status_revisao' => 'nullable|string|in:nao_revisado,pendente_revisao,aprovado,rejeitado',
            'atributos' => 'nullable|array',
            'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
            'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
            'codigos_historicos' => 'nullable|array',
            'codigos_historicos.*.codigo' => 'nullable|string|max:120',
            'codigos_historicos.*.codigo_origem' => 'nullable|string|max:120',
            'codigos_historicos.*.codigo_modelo' => 'nullable|string|max:120',
            'codigos_historicos.*.fonte' => 'nullable|string|max:80',
            'codigos_historicos.*.aba_origem' => 'nullable|string|max:120',
            'codigos_historicos.*.observacoes' => 'nullable|string|max:255',
            'codigo' => 'nullable|string|max:120',
            'codigo_origem' => 'nullable|string|max:120',
            'codigo_modelo' => 'nullable|string|max:120',
            'audit' => 'sometimes|array',
            'audit.label' => 'sometimes|nullable|string|max:255',
            'audit.motivo' => 'sometimes|nullable|string|max:500',
            'audit.origin' => 'sometimes|nullable|in:checkout,cadastro,importacao',
            'audit.metadata' => 'sometimes|array',
            'audit.metadata.carrinho_id' => 'sometimes|nullable|integer|min:1',
        ], [
            'preco.required' => 'Informe o preço da variação.',
            'preco.numeric' => 'Informe um preço válido para a variação.',
            'preco.min' => 'O preço da variação não pode ser negativo.',
            'custo.numeric' => 'Informe um custo válido para a variação.',
            'custo.min' => 'O custo da variação não pode ser negativo.',
            'referencia.max' => 'A referência pode ter no máximo 100 caracteres.',
            'referencia.unique' => 'Esta referência já está em uso em outra variação.',
            'sku_interno.max' => 'O SKU interno pode ter no máximo 120 caracteres.',
            'sku_interno.unique' => 'Este SKU interno já está em uso em outra variação.',
            'chave_variacao.max' => 'A chave da variação pode ter no máximo 255 caracteres.',
            'chave_variacao.unique' => 'Esta chave de variação já está em uso.',
            'codigo_barras.max' => 'O código de barras pode ter no máximo 255 caracteres.',
            'audit.motivo.max' => 'O motivo pode ter no máximo 500 caracteres.',
            'audit.origin.in' => 'A origem da alteração de preço é inválida.',
        ]);
    }

    private function prepararPayloadParaPersistencia(
        array $data,
        int $produtoId,
        ?ProdutoVariacao $variacao = null,
        bool $parcial = false
    ): array {
        $payload = [];

        $campos = [
            'nome',
            'preco',
            'custo',
            'codigo_barras',
            'sku_interno',
            'chave_variacao',
            'dimensao_1',
            'dimensao_2',
            'dimensao_3',
            'cor',
            'lado',
            'material_oficial',
            'acabamento_oficial',
            'conflito_codigo',
            'status_revisao',
        ];

        foreach ($campos as $campo) {
            if (!$parcial || array_key_exists($campo, $data)) {
                $payload[$campo] = $data[$campo] ?? null;
            }
        }

        if ((!$parcial || array_key_exists('conflito_codigo', $data))
            && ($payload['conflito_codigo'] ?? null) === null
        ) {
            $payload['conflito_codigo'] = $variacao?->conflito_codigo ?? false;
        }

        if ((!$parcial || array_key_exists('status_revisao', $data)) && ($payload['status_revisao'] ?? null) === null) {
            $payload['status_revisao'] = $variacao?->status_revisao?->value ?? StatusRevisaoCadastro::NAO_REVISADO->value;
        }

        if (!$parcial || array_key_exists('referencia', $data)) {
            $payload['referencia'] = $this->gerarReferenciaLegadaFallback($data, $variacao, $produtoId);
        }

        return $payload;
    }

    private function precoMudou(ProdutoVariacao $variacao, array $data): bool
    {
        if (!array_key_exists('preco', $data)) {
            return false;
        }

        if ($data['preco'] === null || $data['preco'] === '') {
            return false;
        }

        return $this->formatarPrecoParaComparacao($variacao->preco)
            !== $this->formatarPrecoParaComparacao($data['preco']);
    }

    private function formatarPrecoParaComparacao(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    private function sincronizarPrecoCarrinhosRascunho(ProdutoVariacao $variacao): void
    {
        $novoPreco = number_format((float) $variacao->preco, 2, '.', '');

        DB::table('carrinho_itens as ci')
            ->join('carrinhos as c', 'c.id', '=', 'ci.id_carrinho')
            ->where('ci.id_variacao', $variacao->id)
            ->whereNull('ci.outlet_id')
            ->where('c.status', 'rascunho')
            ->update([
                'ci.preco_unitario' => $novoPreco,
                'ci.subtotal' => DB::raw("ci.quantidade * {$novoPreco}"),
                'ci.updated_at' => now(),
            ]);
    }

    private function sincronizarAtributos(ProdutoVariacao $variacao, ?array $atributosRecebidos): void
    {
        if ($atributosRecebidos === null) {
            return;
        }

        $atributos = [];

        foreach ($atributosRecebidos as $attr) {
            if (!empty($attr['atributo']) && !empty($attr['valor'])) {
                $atributos[$attr['atributo']] = ['valor' => $attr['valor']];
            }
        }

        $existentes = $variacao->atributos()->get()->keyBy('atributo');

        foreach ($atributos as $atributo => $dados) {
            $variacao->atributos()->updateOrCreate(
                ['atributo' => $atributo],
                ['valor' => $dados['valor']]
            );
        }

        $chavesRecebidas = array_keys($atributos);

        $existentes->each(function ($item) use ($chavesRecebidas) {
            if (!in_array($item->atributo, $chavesRecebidas, true)) {
                $item->delete();
            }
        });
    }

    private function sincronizarCodigosHistoricos(
        ProdutoVariacao $variacao,
        array $codigosRecebidos,
        bool $replace
    ): void {
        $existentes = $variacao->codigosHistoricos()->get()->keyBy('hash_conteudo');
        $hashesRecebidos = [];

        foreach ($codigosRecebidos as $codigoData) {
            $hashesRecebidos[] = $codigoData['hash_conteudo'];

            ProdutoVariacaoCodigoHistorico::updateOrCreate(
                [
                    'produto_variacao_id' => $variacao->id,
                    'hash_conteudo' => $codigoData['hash_conteudo'],
                ],
                [
                    'codigo' => $codigoData['codigo'],
                    'codigo_origem' => $codigoData['codigo_origem'],
                    'codigo_modelo' => $codigoData['codigo_modelo'],
                    'fonte' => $codigoData['fonte'],
                    'aba_origem' => $codigoData['aba_origem'],
                    'observacoes' => $codigoData['observacoes'],
                    'principal' => $codigoData['principal'],
                ]
            );
        }

        if (!empty($hashesRecebidos)) {
            $hashPrincipal = $codigosRecebidos[0]['hash_conteudo'];

            $variacao->codigosHistoricos()
                ->where('hash_conteudo', '!=', $hashPrincipal)
                ->update(['principal' => false]);

            $variacao->codigosHistoricos()
                ->where('hash_conteudo', $hashPrincipal)
                ->update(['principal' => true]);
        }

        if ($replace) {
            $existentes->each(function (ProdutoVariacaoCodigoHistorico $codigo) use ($hashesRecebidos) {
                if (!in_array($codigo->hash_conteudo, $hashesRecebidos, true)) {
                    $codigo->delete();
                }
            });
        }
    }

    private function extrairCodigosHistoricos(array $data): array
    {
        $items = [];

        if (array_key_exists('codigos_historicos', $data) && is_array($data['codigos_historicos'])) {
            $items = $data['codigos_historicos'];
        } elseif ($this->payloadTemCodigoHistoricoTopLevel($data)) {
            $items[] = [
                'codigo' => $data['codigo'] ?? null,
                'codigo_origem' => $data['codigo_origem'] ?? null,
                'codigo_modelo' => $data['codigo_modelo'] ?? null,
                'fonte' => $data['fonte_codigo_historico'] ?? null,
                'aba_origem' => $data['aba_origem_codigo_historico'] ?? null,
                'observacoes' => $data['observacoes_codigo_historico'] ?? null,
            ];
        }

        $normalizados = [];
        foreach ($items as $index => $item) {
            $codigo = trim((string) ($item['codigo'] ?? ''));
            $codigoOrigem = trim((string) ($item['codigo_origem'] ?? ''));
            $codigoModelo = trim((string) ($item['codigo_modelo'] ?? ''));

            if ($codigo === '' && $codigoOrigem === '' && $codigoModelo === '') {
                continue;
            }

            $payload = [
                'codigo' => $codigo !== '' ? $codigo : null,
                'codigo_origem' => $codigoOrigem !== '' ? $codigoOrigem : null,
                'codigo_modelo' => $codigoModelo !== '' ? $codigoModelo : null,
                'fonte' => ($fonte = trim((string) ($item['fonte'] ?? ''))) !== '' ? $fonte : null,
                'aba_origem' => ($aba = trim((string) ($item['aba_origem'] ?? ''))) !== '' ? $aba : null,
                'observacoes' => ($obs = trim((string) ($item['observacoes'] ?? ''))) !== '' ? $obs : null,
                'principal' => $index === 0,
            ];

            $payload['hash_conteudo'] = sha1(json_encode([
                $payload['codigo'],
                $payload['codigo_origem'],
                $payload['codigo_modelo'],
                $payload['fonte'],
                $payload['aba_origem'],
            ], JSON_UNESCAPED_UNICODE));

            $normalizados[$payload['hash_conteudo']] = $payload;
        }

        return array_values($normalizados);
    }

    private function payloadTemCodigoHistoricoTopLevel(array $data): bool
    {
        foreach (['codigo', 'codigo_origem', 'codigo_modelo'] as $campo) {
            if (trim((string) ($data[$campo] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
