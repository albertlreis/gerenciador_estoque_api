<?php

namespace App\Services;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\LancamentoFinanceiro;
use App\Repositories\LancamentoFinanceiroRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LancamentoFinanceiroService
{
    public function __construct(
        protected LancamentoFinanceiroRepository $repo
    ) {}

    public function listar(FiltroLancamentoFinanceiroDTO $f): LengthAwarePaginator
    {
        return $this->repo->queryBase($f)->paginate(
            perPage: $f->perPage,
            page: $f->page
        );
    }

    public function listarParaExportacao(FiltroLancamentoFinanceiroDTO $f): Collection
    {
        return $this->repo->queryBase($f)->get();
    }

    public function obter(int $id): LancamentoFinanceiro
    {
        return $this->repo->findOrFail($id);
    }

    public function criar(array $data): LancamentoFinanceiro
    {
        $payload = $this->prepararPayloadParaPersistencia($data);

        $payload['created_by'] = $payload['created_by'] ?? (Auth::id() ?: null);

        $model = $this->repo->create($payload);

        return $model->fresh(['categoria','conta','criador','centroCusto']);
    }

    private function isAutomatico(LancamentoFinanceiro $m): bool
    {
        return !empty($m->pagamento_type) || !empty($m->pagamento_id)
            || (!empty($m->referencia_type) && !empty($m->referencia_id));
    }

    public function atualizar(LancamentoFinanceiro $model, array $data): LancamentoFinanceiro
    {
        if ($this->isAutomatico($model)) {
            // permitir só trocar status (ex.: cancelado) e observações, se quiser
            $allowed = array_intersect_key($data, array_flip(['status', 'observacoes']));
            $payload = $this->prepararPayloadParaPersistencia($allowed, $model);
            $updated = $this->repo->update($model, $payload);
            return $updated->fresh(['categoria','conta','criador','centroCusto']);
        }

        $payload = $this->prepararPayloadParaPersistencia($data, $model);
        $updated = $this->repo->update($model, $payload);
        return $updated->fresh(['categoria','conta','criador','centroCusto']);
    }

    public function remover(LancamentoFinanceiro $model): void
    {
        if ($this->isAutomatico($model)) {
            throw ValidationException::withMessages([
                'lancamento' => 'Este lançamento foi gerado automaticamente e não pode ser removido. Cancele-o (status) ou estorne na origem.'
            ]);
        }
        $this->repo->delete($model);
    }

    /**
     * Totais do ledger respeitando os filtros informados.
     *
     * @return array{
     *   receitas_confirmadas:string,
     *   despesas_confirmadas:string,
     *   saldo_confirmado:string,
     *   cancelados:string,
     *   pago?:string,
     *   pendente?:string,
     *   atrasado?:string
     * }
     */
    public function totais(FiltroLancamentoFinanceiroDTO $f): array
    {
        $base = $this->repo->queryBase($f)->reorder();

        $stConfirmado = LancamentoStatus::CONFIRMADO->value;
        $stCancelado  = LancamentoStatus::CANCELADO->value;

        $tpReceita = LancamentoTipo::RECEITA->value;
        $tpDespesa = LancamentoTipo::DESPESA->value;

        $receitasConfirmadas = (clone $base)
            ->where('status', $stConfirmado)
            ->where('tipo', $tpReceita)
            ->sum('valor');

        $despesasConfirmadas = (clone $base)
            ->where('status', $stConfirmado)
            ->where('tipo', $tpDespesa)
            ->sum('valor');

        $cancelados = (clone $base)
            ->where('status', $stCancelado)
            ->sum('valor');

        $saldo = (float)$receitasConfirmadas - (float)$despesasConfirmadas;

        $out = [
            'receitas_confirmadas' => number_format((float)$receitasConfirmadas, 2, '.', ''),
            'despesas_confirmadas' => number_format((float)$despesasConfirmadas, 2, '.', ''),
            'saldo_confirmado'     => number_format((float)$saldo, 2, '.', ''),
            'cancelados'           => number_format((float)$cancelados, 2, '.', ''),
        ];

        // Compatibilidade (se seu front ainda espera pago/pendente/atrasado)
        $out['pago']     = $out['receitas_confirmadas']; // ou use total confirmado geral, se preferir
        $out['pendente'] = number_format(0, 2, '.', '');
        $out['atrasado'] = number_format(0, 2, '.', '');

        return $out;
    }

    /**
     * Normaliza + valida payload para salvar.
     * Se $current for informado, usa estado atual como fallback.
     */
    private function prepararPayloadParaPersistencia(array $data, ?LancamentoFinanceiro $current = null): array
    {
        $p = $this->normalizarPayload($data);

        // Defaults / fallback do model atual
        $tipo = $p['tipo'] ?? ($current?->tipo?->value ?? null);
        $status = $p['status'] ?? ($current?->status?->value ?? null);

        // tipo obrigatório em criação
        if (!$tipo && !$current) {
            throw ValidationException::withMessages(['tipo' => 'Tipo é obrigatório (receita|despesa).']);
        }

        // status default
        $status = $status ?: LancamentoStatus::CONFIRMADO->value;

        // valida enums (aceita string do request)
        $tipoEnum = $tipo ? LancamentoTipo::tryFrom((string)$tipo) : null;
        if ($tipo && !$tipoEnum) {
            throw ValidationException::withMessages(['tipo' => 'Tipo inválido. Use receita ou despesa.']);
        }

        $statusEnum = LancamentoStatus::tryFrom((string)$status);
        if (!$statusEnum) {
            throw ValidationException::withMessages(['status' => 'Status inválido. Use confirmado ou cancelado.']);
        }

        $p['tipo'] = $tipoEnum?->value;
        $p['status'] = $statusEnum->value;

        // data_movimento: regra do ledger
        // - se veio data_movimento, ok
        // - senão, se veio data_pagamento, usa como data_movimento
        // - senão, se não existe ainda, seta now()
        if (empty($p['data_movimento'])) {
            if (!empty($p['data_pagamento'])) {
                $p['data_movimento'] = Carbon::parse($p['data_pagamento']);
            } elseif (!$current?->data_movimento) {
                $p['data_movimento'] = now();
            }
        }

        // validações mínimas
        if (isset($p['descricao']) && $p['descricao'] === '') {
            throw ValidationException::withMessages(['descricao' => 'Descrição não pode ser vazia.']);
        }

        if (array_key_exists('valor', $p)) {
            $v = (float) $p['valor'];
            if ($v <= 0) {
                throw ValidationException::withMessages(['valor' => 'Valor deve ser maior que zero.']);
            }
        }

        // Se cancelado, não precisa “zerar datas”; mas você pode decidir:
        // Ex.: manter data_movimento para histórico e relatórios (recomendado).

        // Retorna apenas campos relevantes (evita “lixo” vindo do request)
        return Arr::only($p, [
            'descricao','tipo','status',
            'categoria_id','centro_custo_id','conta_id',
            'valor',
            'data_pagamento','data_movimento','competencia',
            'observacoes',
            'recibo_pessoa_nome','recibo_pessoa_documento',
            'referencia_type','referencia_id',
            'pagamento_type','pagamento_id',
            'created_by',
        ]);
    }

    private function normalizarPayload(array $data): array
    {
        $p = $data;

        if (array_key_exists('descricao', $p)) {
            $p['descricao'] = trim((string)$p['descricao']);
        }

        if (array_key_exists('tipo', $p) && $p['tipo'] !== null) {
            $p['tipo'] = strtolower((string)$p['tipo']);
        }

        if (array_key_exists('status', $p) && $p['status'] !== null) {
            $p['status'] = strtolower((string)$p['status']);
        }

        foreach (['recibo_pessoa_nome', 'recibo_pessoa_documento'] as $campo) {
            if (array_key_exists($campo, $p)) {
                $valor = trim((string)($p[$campo] ?? ''));
                $p[$campo] = $valor !== '' ? $valor : null;
            }
        }

        if (array_key_exists('data_pagamento', $p)) {
            $p['data_pagamento'] = $p['data_pagamento'] ? Carbon::parse($p['data_pagamento']) : null;
        }

        if (array_key_exists('data_movimento', $p)) {
            $p['data_movimento'] = $p['data_movimento'] ? Carbon::parse($p['data_movimento']) : null;
        }

        if (array_key_exists('competencia', $p)) {
            $p['competencia'] = $p['competencia'] ? Carbon::parse($p['competencia'])->toDateString() : null;
        }

        return $p;
    }

    public function dadosRecibo(LancamentoFinanceiro $lancamento): array
    {
        $this->validarPodeEmitirRecibo($lancamento);

        $tipo = $lancamento->tipo?->value ?? (string) $lancamento->tipo;
        $empresa = [
            'nome' => config('app.empresa_nome', 'G. P COMERCIO VAREJISTA DE MOVEIS LTDA'),
            'documento' => config('app.empresa_documento', '54.129.336/0001-88'),
            'ie' => config('app.empresa_ie', config('app.empresa_inscricao_estadual', '')),
            'endereco' => config('app.empresa_endereco', 'TV RUI BARBOSA, 1820'),
            'telefone' => config('app.empresa_telefone', '91984278816'),
            'cep' => config('app.empresa_cep', '66035-220'),
            'cidade' => config('app.empresa_cidade', 'Belém'),
            'uf' => config('app.empresa_uf', 'PA'),
        ];

        $pessoaNome = trim((string) $lancamento->recibo_pessoa_nome);
        $valorExtenso = ucfirst($this->valorPorExtenso((float) $lancamento->valor));
        $descricao = trim((string) $lancamento->descricao);
        $dataMovimento = $lancamento->data_movimento
            ? Carbon::parse($lancamento->data_movimento)
            : now();

        if ($tipo === LancamentoTipo::DESPESA->value) {
            $texto = "Recebi de {$empresa['nome']} a importância de {$valorExtenso} referente ao pagamento de {$descricao}.";
            $assinatura = $pessoaNome;
        } else {
            $texto = "Recebemos de {$pessoaNome} a importância de {$valorExtenso} referente ao pagamento de {$descricao}.";
            $assinatura = $empresa['nome'];
        }

        return [
            'lancamento' => $lancamento,
            'empresa' => $empresa,
            'pessoa_nome' => $pessoaNome,
            'pessoa_documento' => $lancamento->recibo_pessoa_documento,
            'valor_formatado' => number_format((float) $lancamento->valor, 2, ',', '.'),
            'valor_extenso' => $valorExtenso,
            'texto' => $texto,
            'cidade_data' => 'Belém (PA), ' . $this->dataPorExtenso($dataMovimento),
            'assinatura' => $assinatura,
        ];
    }

    private function validarPodeEmitirRecibo(LancamentoFinanceiro $lancamento): void
    {
        $tipo = $lancamento->tipo?->value ?? (string) $lancamento->tipo;
        $status = $lancamento->status?->value ?? (string) $lancamento->status;

        if (!in_array($tipo, [LancamentoTipo::RECEITA->value, LancamentoTipo::DESPESA->value], true)) {
            throw ValidationException::withMessages([
                'lancamento' => 'Recibo disponível apenas para receitas e despesas.',
            ]);
        }

        if ($status !== LancamentoStatus::CONFIRMADO->value) {
            throw ValidationException::withMessages([
                'lancamento' => 'Recibo disponível apenas para movimentos confirmados.',
            ]);
        }

        if (trim((string) $lancamento->recibo_pessoa_nome) === '') {
            throw ValidationException::withMessages([
                'recibo_pessoa_nome' => 'Informe a pessoa do recibo antes de emitir.',
            ]);
        }
    }

    private function valorPorExtenso(float $valor): string
    {
        $centavos = (int) round(($valor - floor($valor)) * 100);
        $reais = (int) floor($valor);
        $partes = [];

        if ($reais > 0) {
            $partes[] = $this->numeroPorExtenso($reais) . ' ' . ($reais === 1 ? 'real' : 'reais');
        }

        if ($centavos > 0) {
            $partes[] = $this->numeroPorExtenso($centavos) . ' ' . ($centavos === 1 ? 'centavo' : 'centavos');
        }

        return $partes ? implode(' e ', $partes) : 'zero reais';
    }

    private function numeroPorExtenso(int $numero): string
    {
        if ($numero === 0) {
            return 'zero';
        }

        $unidades = [
            1 => 'um',
            2 => 'dois',
            3 => 'três',
            4 => 'quatro',
            5 => 'cinco',
            6 => 'seis',
            7 => 'sete',
            8 => 'oito',
            9 => 'nove',
            10 => 'dez',
            11 => 'onze',
            12 => 'doze',
            13 => 'treze',
            14 => 'quatorze',
            15 => 'quinze',
            16 => 'dezesseis',
            17 => 'dezessete',
            18 => 'dezoito',
            19 => 'dezenove',
        ];
        $dezenas = [
            20 => 'vinte',
            30 => 'trinta',
            40 => 'quarenta',
            50 => 'cinquenta',
            60 => 'sessenta',
            70 => 'setenta',
            80 => 'oitenta',
            90 => 'noventa',
        ];
        $centenas = [
            100 => 'cem',
            200 => 'duzentos',
            300 => 'trezentos',
            400 => 'quatrocentos',
            500 => 'quinhentos',
            600 => 'seiscentos',
            700 => 'setecentos',
            800 => 'oitocentos',
            900 => 'novecentos',
        ];

        if ($numero < 20) {
            return $unidades[$numero];
        }

        if ($numero < 100) {
            $dezena = intdiv($numero, 10) * 10;
            $resto = $numero % 10;

            return $dezenas[$dezena] . ($resto ? ' e ' . $this->numeroPorExtenso($resto) : '');
        }

        if ($numero < 1000) {
            if ($numero === 100) {
                return 'cem';
            }

            $centena = intdiv($numero, 100) * 100;
            $resto = $numero % 100;
            $prefixo = $centena === 100 ? 'cento' : $centenas[$centena];

            return $prefixo . ($resto ? ' e ' . $this->numeroPorExtenso($resto) : '');
        }

        if ($numero < 1000000) {
            $milhares = intdiv($numero, 1000);
            $resto = $numero % 1000;
            $prefixo = $milhares === 1 ? 'mil' : $this->numeroPorExtenso($milhares) . ' mil';

            return $prefixo . ($resto ? ($resto < 100 ? ' e ' : ' ') . $this->numeroPorExtenso($resto) : '');
        }

        $milhoes = intdiv($numero, 1000000);
        $resto = $numero % 1000000;
        $prefixo = $this->numeroPorExtenso($milhoes) . ' ' . ($milhoes === 1 ? 'milhão' : 'milhões');

        return $prefixo . ($resto ? ($resto < 100 ? ' e ' : ' ') . $this->numeroPorExtenso($resto) : '');
    }

    private function dataPorExtenso(Carbon $data): string
    {
        $meses = [
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'março',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
        ];

        return $data->format('d') . ' de ' . $meses[(int) $data->format('n')] . ' de ' . $data->format('Y');
    }
}
