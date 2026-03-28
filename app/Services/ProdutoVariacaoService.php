<?php

namespace App\Services;

use App\Enums\StatusRevisaoCadastro;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoCodigoHistorico;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProdutoVariacaoService
{
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

        return $variacao->refresh()->load('atributos', 'codigosHistoricos', 'imagem');
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

            $payload = $this->prepararPayloadParaPersistencia(
                $variacaoData,
                $produtoId,
                $variacaoExistente
            );

            $variacao = ProdutoVariacao::updateOrCreate(
                [
                    'id' => $variacaoData['id'] ?? null,
                    'produto_id' => $produtoId,
                ],
                $payload
            );

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
        }

        $produto->variacoes()->whereNotIn('id', $idsRecebidos)->delete();
    }

    public function atualizarIndividual(ProdutoVariacao $variacao, array $data): ProdutoVariacao
    {
        $this->makeValidator($data, $variacao)->validate();

        $updates = $this->prepararPayloadParaPersistencia(
            $data,
            $variacao->produto_id,
            $variacao,
            parcial: true
        );

        if (!empty($updates)) {
            $variacao->fill($updates);
        }

        if ($variacao->isDirty()) {
            $variacao->save();
        }

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

        return $variacao->refresh()->load('atributos', 'codigosHistoricos', 'imagem');
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

    private function makeValidator(array $data, ?ProdutoVariacao $variacao = null): ValidatorContract
    {
        $ignoreId = $variacao?->id ?? ($data['id'] ?? null);

        return Validator::make($data, [
            'id' => 'nullable|integer|exists:produto_variacoes,id',
            'preco' => array_key_exists('preco', $data) ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
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
