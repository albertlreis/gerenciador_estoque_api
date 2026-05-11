<?php

namespace App\Integrations\ContaAzul\Services;

use App\Enums\ContaStatus;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContaAzulLocalCreationService
{
    /**
     * @return array<string, mixed>
     */
    public function preview(string $entidade, int $stagingId, ?int $lojaId = null): array
    {
        [$tipo, $table, $row, $payload] = $this->loadStaging($entidade, $stagingId, $lojaId);

        return match ($tipo) {
            ContaAzulEntityType::PESSOA => $this->previewPessoa($row, $payload),
            ContaAzulEntityType::TITULO => $this->previewFinanceiro($tipo, $row, $payload, $lojaId),
            ContaAzulEntityType::CONTA_PAGAR => $this->previewFinanceiro($tipo, $row, $payload, $lojaId),
            default => throw new ContaAzulException('Criacao local nao suportada para esta entidade.', 'criacao_local_entidade_nao_suportada'),
        };
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function criarLocal(string $entidade, int $stagingId, ?int $lojaId, array $request): array
    {
        [$tipo, $table, $row, $payload] = $this->loadStaging($entidade, $stagingId, $lojaId);

        return DB::transaction(function () use ($tipo, $table, $row, $payload, $lojaId, $request) {
            return match ($tipo) {
                ContaAzulEntityType::PESSOA => $this->criarPessoa($table, $row, $payload, $lojaId, $request),
                ContaAzulEntityType::TITULO => $this->criarContaReceber($table, $row, $payload, $lojaId, $request),
                ContaAzulEntityType::CONTA_PAGAR => $this->criarContaPagar($table, $row, $payload, $lojaId, $request),
                default => throw new ContaAzulException('Criacao local nao suportada para esta entidade.', 'criacao_local_entidade_nao_suportada'),
            };
        });
    }

    /**
     * @return array{0:string, 1:string, 2:object, 3:array<string, mixed>}
     */
    private function loadStaging(string $entidade, int $stagingId, ?int $lojaId): array
    {
        $tipo = $this->normalizeEntidade($entidade);
        $table = $this->stagingTableFor($tipo);
        $row = DB::table($table)
            ->where('id', $stagingId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
            ->first();

        if (!$row) {
            throw new ContaAzulException('Pendencia Conta Azul nao encontrada.', 'pendencia_nao_encontrada');
        }

        $payload = json_decode((string) $row->payload_json, true);
        if (!is_array($payload)) {
            throw new ContaAzulException('Payload Conta Azul invalido.', 'payload_invalido');
        }

        return [$tipo, $table, $row, $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPessoa(object $row, array $payload): array
    {
        return [
            'entidade' => ContaAzulEntityType::PESSOA,
            'tipo_local' => 'cliente',
            'tipos_locais' => ['cliente', 'fornecedor'],
            'identificador_externo' => (string) $row->identificador_externo,
            'dados' => $this->personDataFromPayload($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewFinanceiro(string $tipo, object $row, array $payload, ?int $lojaId): array
    {
        $tipoLocal = $tipo === ContaAzulEntityType::CONTA_PAGAR ? 'conta_pagar' : 'conta_receber';
        $valorBruto = $this->money($this->first($payload, ['valor', 'valorTitulo', 'valorLiquido', 'valorParcela', 'valorTotal', 'total'])) ?? 0.0;
        $valorPago = $this->detectPaidValue($payload, $valorBruto);
        $dataPagamento = $this->date($this->first($payload, ['dataPagamento', 'dataBaixa', 'dataRecebimento', 'dataLiquidacao']))
            ?: $this->date($this->first($payload, ['dataVencimento', 'vencimento', 'data_vencimento']))
            ?: now()->format('Y-m-d');

        return [
            'entidade' => $tipo,
            'tipo_local' => $tipoLocal,
            'identificador_externo' => (string) $row->identificador_externo,
            'dados' => [
                'descricao' => $this->first($payload, ['descricao', 'nome', 'historico', 'observacao', 'titulo']) ?: 'Título Conta Azul ' . $row->identificador_externo,
                'numero_documento' => $this->first($payload, ['numero_documento', 'numeroDocumento', 'numero', 'id']),
                'data_emissao' => $this->date($this->first($payload, ['dataEmissao', 'data_emissao', 'emissao', 'dataCriacao', 'data'])),
                'data_vencimento' => $this->date($this->first($payload, ['dataVencimento', 'vencimento', 'data_vencimento'])) ?: now()->format('Y-m-d'),
                'valor_bruto' => $this->decimal($valorBruto),
                'desconto' => $this->decimal($this->money($this->first($payload, ['desconto'])) ?? 0.0),
                'juros' => $this->decimal($this->money($this->first($payload, ['juros'])) ?? 0.0),
                'multa' => $this->decimal($this->money($this->first($payload, ['multa'])) ?? 0.0),
                'categoria_id' => null,
                'centro_custo_id' => null,
                'observacoes' => 'Criado a partir da pendência Conta Azul #' . $row->id,
            ],
            'pessoa' => $this->previewPessoaFinanceira($tipoLocal, $payload, $lojaId),
            'baixa' => [
                'requerida' => $valorPago > 0,
                'valor' => $this->decimal($valorPago),
                'data_pagamento' => $dataPagamento,
                'conta_financeira_id' => '',
                'forma_pagamento' => $this->suggestPaymentMethod($payload),
                'parcial' => $valorPago > 0 && $valorPago + 0.005 < $valorBruto,
            ],
            'opcoes' => $this->options(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previewPessoaFinanceira(string $tipoLocal, array $payload, ?int $lojaId): ?array
    {
        $externalId = $this->first($payload, $tipoLocal === 'conta_pagar'
            ? ['idFornecedor', 'fornecedorId', 'idPessoa', 'pessoaId', 'idCliente', 'clienteId']
            : ['idCliente', 'clienteId', 'idPessoa', 'pessoaId']);

        $mapType = $tipoLocal === 'conta_pagar' ? ContaAzulEntityType::FORNECEDOR : ContaAzulEntityType::PESSOA;
        if ($externalId !== '') {
            $idLocal = ContaAzulMapeamento::query()
                ->where('tipo_entidade', $mapType)
                ->where('id_externo', $externalId)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->value('id_local');

            if ($idLocal) {
                return [
                    'modo' => 'usar_existente',
                    'tipo_local' => $tipoLocal === 'conta_pagar' ? 'fornecedor' : 'cliente',
                    'id_local' => (int) $idLocal,
                    'identificador_externo' => $externalId,
                    'label' => $tipoLocal === 'conta_pagar'
                        ? (Fornecedor::query()->whereKey($idLocal)->value('nome') ?: 'Fornecedor #' . $idLocal)
                        : (Cliente::query()->whereKey($idLocal)->value('nome') ?: 'Cliente #' . $idLocal),
                ];
            }
        }

        $personPayload = $this->nestedPersonPayload($payload);
        if ($personPayload === [] && $externalId === '') {
            return null;
        }

        return [
            'modo' => 'criar',
            'tipo_local' => $tipoLocal === 'conta_pagar' ? 'fornecedor' : 'cliente',
            'identificador_externo' => $externalId ?: null,
            'dados' => $this->personDataFromPayload($personPayload ?: $payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarPessoa(string $table, object $row, array $payload, ?int $lojaId, array $request): array
    {
        $tipoLocal = strtolower((string) ($request['tipo_local'] ?? 'cliente'));
        $dados = (array) ($request['dados'] ?? []);

        if ($tipoLocal === 'fornecedor') {
            $fornecedor = $this->createFornecedor($dados);
            $this->saveMapping(ContaAzulEntityType::FORNECEDOR, (int) $fornecedor->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
            $this->markStagingCreated($table, $row, (int) $fornecedor->id, 'Fornecedor ' . $fornecedor->nome);

            return ['status' => 'conciliado', 'tipo_local' => 'fornecedor', 'id_local' => (int) $fornecedor->id];
        }

        if ($tipoLocal !== 'cliente') {
            throw ValidationException::withMessages(['tipo_local' => 'Tipo local inválido.']);
        }

        $cliente = $this->createCliente($dados);
        $this->saveMapping(ContaAzulEntityType::PESSOA, (int) $cliente->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $cliente->id, 'Cliente ' . $cliente->nome);

        return ['status' => 'conciliado', 'tipo_local' => 'cliente', 'id_local' => (int) $cliente->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarContaReceber(string $table, object $row, array $payload, ?int $lojaId, array $request): array
    {
        $dados = $this->validateFinancialData((array) ($request['dados'] ?? []));
        $pessoa = $this->resolvePessoaFinanceira((array) ($request['pessoa'] ?? []), ContaAzulEntityType::PESSOA, $lojaId);
        if ($pessoa && empty($dados['observacoes'])) {
            $dados['observacoes'] = 'Cliente Conta Azul: ' . $pessoa['label'];
        }

        $dados = $this->withReceivableTotals($dados);
        $conta = ContaReceber::create($dados);

        $baixaInput = (array) ($request['baixa'] ?? []);
        $expectedPaid = $this->detectPaidValue($payload, (float) $dados['valor_liquido']);
        if ($expectedPaid > 0 && empty($baixaInput['valor'])) {
            $baixaInput['valor'] = $this->decimal($expectedPaid);
        }
        $baixa = $this->validateBaixa($baixaInput, (float) $dados['valor_liquido']);
        if ($baixa) {
            ContaReceberPagamento::create([
                'conta_receber_id' => $conta->id,
                'data_pagamento' => $baixa['data_pagamento'],
                'valor' => $baixa['valor'],
                'forma_pagamento' => $baixa['forma_pagamento'],
                'observacoes' => 'Baixa criada a partir da Conta Azul',
                'usuario_id' => auth()->id(),
                'conta_financeira_id' => $baixa['conta_financeira_id'],
            ]);
            $this->syncReceberStatus($conta->fresh());
        }

        $this->saveMapping(ContaAzulEntityType::TITULO, (int) $conta->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $conta->id, 'Conta a receber #' . $conta->id);

        return ['status' => 'conciliado', 'tipo_local' => 'conta_receber', 'id_local' => (int) $conta->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarContaPagar(string $table, object $row, array $payload, ?int $lojaId, array $request): array
    {
        $dados = $this->validateFinancialData((array) ($request['dados'] ?? []));
        $pessoa = $this->resolvePessoaFinanceira((array) ($request['pessoa'] ?? []), ContaAzulEntityType::FORNECEDOR, $lojaId);
        if ($pessoa) {
            $dados['fornecedor_id'] = $pessoa['id_local'];
        }

        $conta = ContaPagar::create([
            'fornecedor_id' => $dados['fornecedor_id'] ?? null,
            'descricao' => $dados['descricao'],
            'numero_documento' => $dados['numero_documento'] ?? null,
            'data_emissao' => $dados['data_emissao'] ?? null,
            'data_vencimento' => $dados['data_vencimento'],
            'valor_bruto' => $dados['valor_bruto'],
            'desconto' => $dados['desconto'] ?? 0,
            'juros' => $dados['juros'] ?? 0,
            'multa' => $dados['multa'] ?? 0,
            'status' => ContaStatus::ABERTA->value,
            'forma_pagamento' => $dados['forma_pagamento'] ?? null,
            'categoria_id' => $dados['categoria_id'] ?? null,
            'centro_custo_id' => $dados['centro_custo_id'] ?? null,
            'observacoes' => $dados['observacoes'] ?? null,
        ]);

        $valorLiquido = max(0, (float) $conta->valor_liquido);
        $baixaInput = (array) ($request['baixa'] ?? []);
        $expectedPaid = $this->detectPaidValue($payload, $valorLiquido);
        if ($expectedPaid > 0 && empty($baixaInput['valor'])) {
            $baixaInput['valor'] = $this->decimal($expectedPaid);
        }
        $baixa = $this->validateBaixa($baixaInput, $valorLiquido);
        if ($baixa) {
            ContaPagarPagamento::create([
                'conta_pagar_id' => $conta->id,
                'data_pagamento' => $baixa['data_pagamento'],
                'valor' => $baixa['valor'],
                'forma_pagamento' => $baixa['forma_pagamento'],
                'observacoes' => 'Baixa criada a partir da Conta Azul',
                'usuario_id' => auth()->id(),
                'conta_financeira_id' => $baixa['conta_financeira_id'],
            ]);
            $this->syncPagarStatus($conta->fresh());
        }

        $this->saveMapping(ContaAzulEntityType::CONTA_PAGAR, (int) $conta->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $conta->id, 'Conta a pagar #' . $conta->id);

        return ['status' => 'conciliado', 'tipo_local' => 'conta_pagar', 'id_local' => (int) $conta->id];
    }

    /**
     * @return array{id_local:int, label:string}|null
     */
    private function resolvePessoaFinanceira(array $input, string $mapType, ?int $lojaId): ?array
    {
        $modo = strtolower((string) ($input['modo'] ?? ''));
        if ($modo === 'usar_existente') {
            $id = (int) ($input['id_local'] ?? 0);
            if ($id <= 0) {
                throw ValidationException::withMessages(['pessoa.id_local' => 'Informe o ID local da pessoa.']);
            }

            return ['id_local' => $id, 'label' => 'Registro local #' . $id];
        }

        if ($modo !== 'criar') {
            return null;
        }

        $externalId = (string) ($input['identificador_externo'] ?? '');
        if ($mapType === ContaAzulEntityType::FORNECEDOR) {
            $fornecedor = $this->createFornecedor((array) ($input['dados'] ?? []));
            if ($externalId !== '') {
                $this->saveMapping($mapType, (int) $fornecedor->id, $externalId, $lojaId, null, 'manual_criacao');
            }

            return ['id_local' => (int) $fornecedor->id, 'label' => $fornecedor->nome];
        }

        $cliente = $this->createCliente((array) ($input['dados'] ?? []));
        if ($externalId !== '') {
            $this->saveMapping($mapType, (int) $cliente->id, $externalId, $lojaId, null, 'manual_criacao');
        }

        return ['id_local' => (int) $cliente->id, 'label' => $cliente->nome];
    }

    private function createCliente(array $dados): Cliente
    {
        $validated = Validator::make($dados, [
            'tipo' => ['nullable', 'in:pf,pj'],
            'nome' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
        ])->validate();

        $validated['tipo'] = $validated['tipo'] ?? $this->tipoPessoaFromDocumento($validated['documento'] ?? '');

        return Cliente::create($validated);
    }

    private function createFornecedor(array $dados): Fornecedor
    {
        $validated = Validator::make($dados, [
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'endereco' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
        ])->validate();
        $validated['status'] = 1;

        return Fornecedor::create($validated);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFinancialData(array $dados): array
    {
        return Validator::make($dados, [
            'descricao' => ['required', 'string', 'max:180'],
            'numero_documento' => ['nullable', 'string', 'max:80'],
            'data_emissao' => ['nullable', 'date'],
            'data_vencimento' => ['required', 'date'],
            'valor_bruto' => ['required', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'juros' => ['nullable', 'numeric', 'min:0'],
            'multa' => ['nullable', 'numeric', 'min:0'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias_financeiras,id'],
            'centro_custo_id' => ['nullable', 'integer', 'exists:centros_custo,id'],
            'observacoes' => ['nullable', 'string'],
        ])->validate();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateBaixa(array $baixa, float $valorLiquido): ?array
    {
        $valor = (float) ($baixa['valor'] ?? 0);
        if ($valor <= 0) {
            return null;
        }

        $validated = Validator::make($baixa, [
            'data_pagamento' => ['required', 'date'],
            'valor' => ['required', 'numeric', 'gt:0'],
            'forma_pagamento' => ['required', 'string', 'max:50'],
            'conta_financeira_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
        ])->validate();

        if ((float) $validated['valor'] > $valorLiquido + 0.005) {
            throw ValidationException::withMessages(['baixa.valor' => 'Valor pago não pode exceder o valor líquido.']);
        }

        $forma = (string) $validated['forma_pagamento'];
        $legacy = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];
        if (!in_array(mb_strtoupper($forma), $legacy, true) && !FormaPagamento::query()->where('nome', $forma)->exists()) {
            throw ValidationException::withMessages(['baixa.forma_pagamento' => 'Forma de pagamento inválida.']);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function withReceivableTotals(array $dados): array
    {
        $valorBruto = (float) $dados['valor_bruto'];
        $desconto = (float) ($dados['desconto'] ?? 0);
        $juros = (float) ($dados['juros'] ?? 0);
        $multa = (float) ($dados['multa'] ?? 0);
        $liquido = max(0, $valorBruto - $desconto + $juros + $multa);

        return array_merge($dados, [
            'valor_liquido' => $this->decimal($liquido),
            'valor_recebido' => '0.00',
            'saldo_aberto' => $this->decimal($liquido),
            'status' => ContaStatus::ABERTA->value,
        ]);
    }

    private function syncReceberStatus(ContaReceber $conta): void
    {
        $liquido = (float) $conta->valor_liquido;
        $recebido = (float) $conta->pagamentos()->sum('valor');
        $saldo = max(0, $liquido - $recebido);

        DB::table('contas_receber')->where('id', $conta->id)->update([
            'valor_recebido' => $this->decimal($recebido),
            'saldo_aberto' => $this->decimal($saldo),
            'status' => $recebido >= $liquido - 0.005 ? ContaStatus::PAGA->value : ContaStatus::PARCIAL->value,
            'updated_at' => now(),
        ]);
    }

    private function syncPagarStatus(ContaPagar $conta): void
    {
        $liquido = (float) $conta->valor_liquido;
        $pago = (float) $conta->pagamentos()->sum('valor');

        $conta->status = $pago >= $liquido - 0.005 ? ContaStatus::PAGA->value : ContaStatus::PARCIAL->value;
        $conta->saveQuietly();
    }

    private function saveMapping(string $tipo, int $idLocal, string $idExterno, ?int $lojaId, ?object $row, string $origin): void
    {
        ContaAzulMapeamento::updateOrCreate(
            [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipo,
                'id_local' => $idLocal,
            ],
            [
                'id_externo' => $idExterno,
                'origem_inicial' => $origin,
                'hash_payload_externo' => $row ? hash('sha256', (string) $row->payload_json) : null,
                'sincronizado_em' => now(),
                'metadata_json' => array_filter([
                    'staging_id' => $row->id ?? null,
                    'origem' => 'criacao_local_conta_azul',
                ]),
            ]
        );
    }

    private function markStagingCreated(string $table, object $row, int $idLocal, string $label): void
    {
        DB::table($table)->where('id', $row->id)->update([
            'status_conciliacao' => 'conciliado',
            'observacao_conciliacao' => 'Registro local criado a partir da Conta Azul',
            'candidato_id_local' => $idLocal,
            'candidato_score' => 100,
            'candidato_motivo' => 'Criado localmente',
            'candidato_json' => json_encode([
                'id_local' => $idLocal,
                'score' => 100,
                'motivo' => 'Criado localmente',
                'label' => $label,
            ], JSON_UNESCAPED_UNICODE),
            'conciliacao_origem' => 'manual_criacao',
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $formas = collect(['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'])
            ->concat(FormaPagamento::query()->where('ativo', true)->orderBy('nome')->pluck('nome'))
            ->unique()
            ->values()
            ->all();

        return [
            'contas_financeiras' => ContaFinanceira::query()
                ->where('ativo', true)
                ->orderByDesc('padrao')
                ->orderBy('nome')
                ->get(['id', 'nome', 'padrao'])
                ->map(fn (ContaFinanceira $conta) => [
                    'id' => (int) $conta->id,
                    'nome' => $conta->nome,
                    'padrao' => (bool) $conta->padrao,
                ])
                ->all(),
            'formas_pagamento' => $formas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personDataFromPayload(array $payload): array
    {
        $documento = $this->first($payload, ['cpf', 'cnpj', 'documento', 'numeroDocumento', 'cpfCnpj']);

        return [
            'tipo' => $this->tipoPessoaFromDocumento($documento),
            'nome' => $this->first($payload, ['nome', 'razaoSocial', 'nomeFantasia', 'descricao']) ?: 'Pessoa Conta Azul',
            'nome_fantasia' => $this->first($payload, ['nomeFantasia']),
            'documento' => $documento,
            'cnpj' => $documento,
            'email' => $this->first($payload, ['email', 'emailPrincipal']),
            'telefone' => $this->first($payload, ['telefone', 'celular', 'phone', 'mobile']),
            'whatsapp' => $this->first($payload, ['celular', 'whatsapp']),
            'observacoes' => 'Criado a partir da Conta Azul',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedPersonPayload(array $payload): array
    {
        foreach (['cliente', 'fornecedor', 'pessoa', 'customer', 'supplier'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    private function detectPaidValue(array $payload, float $valorBruto): float
    {
        $valor = $this->money($this->first($payload, ['valorPago', 'valor_pago', 'valorBaixado', 'valorRecebido', 'valorPagoTotal']));
        if ($valor !== null) {
            return max(0, $valor);
        }

        $status = mb_strtolower($this->first($payload, ['status', 'situacao']));
        if ($valorBruto > 0 && preg_match('/pago|paga|liquidado|liquidada|baixado|baixada/', $status)) {
            return $valorBruto;
        }

        return 0.0;
    }

    private function suggestPaymentMethod(array $payload): string
    {
        $forma = mb_strtoupper($this->first($payload, ['formaPagamento', 'forma_pagamento', 'meioPagamento', 'metodoPagamento']));
        $legacy = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];

        return in_array($forma, $legacy, true) ? $forma : '';
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function first(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return '';
    }

    private function money(string $value): ?float
    {
        if (trim($value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_contains($value, ',')
            ? str_replace(',', '.', str_replace(['.', ' '], ['', ''], $value))
            : str_replace([' ', ','], ['', ''], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function date(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function tipoPessoaFromDocumento(string $documento): string
    {
        $digits = preg_replace('/\D+/', '', $documento) ?? '';

        return strlen($digits) > 11 ? 'pj' : 'pf';
    }

    private function normalizeEntidade(string $entidade): string
    {
        return match (strtolower(trim($entidade))) {
            'pessoa', 'pessoas' => ContaAzulEntityType::PESSOA,
            'titulo', 'titulos', 'financeiro', 'conta_receber', 'contas_receber' => ContaAzulEntityType::TITULO,
            'conta_pagar', 'contas_pagar', 'contas-pagar' => ContaAzulEntityType::CONTA_PAGAR,
            default => throw new ContaAzulException('Entidade Conta Azul invalida para criacao local.', 'entidade_invalida'),
        };
    }

    private function stagingTableFor(string $tipo): string
    {
        return match ($tipo) {
            ContaAzulEntityType::PESSOA => 'stg_conta_azul_pessoas',
            ContaAzulEntityType::TITULO => 'stg_conta_azul_financeiro',
            ContaAzulEntityType::CONTA_PAGAR => 'stg_conta_azul_contas_pagar',
            default => throw new ContaAzulException('Entidade Conta Azul invalida para criacao local.', 'entidade_invalida'),
        };
    }
}
