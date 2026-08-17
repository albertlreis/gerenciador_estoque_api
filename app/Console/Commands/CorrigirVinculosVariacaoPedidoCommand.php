<?php

namespace App\Console\Commands;

use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoImportacaoItem;
use App\Models\PedidoItem;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Services\AuditoriaEventoService;
use App\Services\EntregaProdutoService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CorrigirVinculosVariacaoPedidoCommand extends Command
{
    protected $signature = 'pedidos:corrigir-vinculos-variacao
        {--pedido= : ID do pedido que sera analisado}
        {--item=* : Correcao no formato pedido_item_id:variacao_correta_id}
        {--aplicar : Persiste as correcoes; sem esta opcao o comando apenas simula}';

    protected $description = 'Corrige vinculos de variacao em itens de pedido ainda sem historico operacional.';

    public function handle(
        EntregaProdutoService $entregas,
        AuditoriaEventoService $auditoria
    ): int {
        $pedidoId = filter_var($this->option('pedido'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $pedido = $pedidoId ? Pedido::query()->find($pedidoId) : null;
        if (! $pedido) {
            $this->error('Informe um pedido existente com --pedido=<id>.');

            return self::FAILURE;
        }

        [$mapeamentos, $erros] = $this->interpretarMapeamentos((array) $this->option('item'));
        if ($erros !== []) {
            foreach ($erros as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $analises = $this->analisar($pedido, $mapeamentos);
        $this->table(
            ['Item', 'Variacao atual', 'Variacao correta', 'Resultado'],
            $analises->map(fn (array $linha) => [
                $linha['pedido_item_id'],
                $linha['variacao_anterior_id'] ?: '-',
                $linha['variacao_nova_id'],
                $linha['resultado'],
            ])->all()
        );

        $bloqueados = $analises->where('aplicavel', false)
            ->reject(fn (array $linha) => $linha['resultado'] === 'sem_alteracao');
        if ($bloqueados->isNotEmpty()) {
            $this->error('Nenhuma alteracao foi aplicada porque ha itens invalidos ou com historico operacional.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('aplicar')) {
            $this->info('Simulacao concluida. Execute novamente com --aplicar para persistir.');

            return self::SUCCESS;
        }

        $aplicaveis = $analises->where('aplicavel', true)->values();
        if ($aplicaveis->isEmpty()) {
            $this->info('Nenhuma alteracao necessaria. Os vinculos informados ja estao corretos.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($pedido, $aplicaveis, $entregas, $auditoria): void {
            foreach ($aplicaveis as $linha) {
                $item = PedidoItem::query()->lockForUpdate()->findOrFail($linha['pedido_item_id']);
                $anterior = (int) $item->id_variacao;
                $item->update(['id_variacao' => $linha['variacao_nova_id']]);
                $variacaoNova = ProdutoVariacao::query()->findOrFail($linha['variacao_nova_id']);
                PedidoImportacaoItem::query()
                    ->where('pedido_item_id', $item->id)
                    ->get()
                    ->each(function (PedidoImportacaoItem $registro) use ($variacaoNova): void {
                        $confirmados = (array) ($registro->dados_confirmados_json ?? []);
                        $confirmados['produto_id'] = (int) $variacaoNova->produto_id;
                        $confirmados['id_variacao'] = (int) $variacaoNova->id;
                        $registro->update([
                            'produto_id' => $variacaoNova->produto_id,
                            'produto_variacao_id' => $variacaoNova->id,
                            'dados_confirmados_json' => $confirmados,
                        ]);
                    });

                $auditoria->registrar(
                    module: 'pedidos',
                    action: 'pedido_item.variacao_corrigida',
                    label: "Variacao do item #{$item->id} do pedido #{$pedido->id} corrigida",
                    auditable: $pedido,
                    mudancas: [[
                        'campo' => "itens.{$item->id}.id_variacao",
                        'old' => $anterior,
                        'new' => (int) $linha['variacao_nova_id'],
                        'value_type' => 'integer',
                    ]],
                    metadata: [
                        'pedido_item_id' => (int) $item->id,
                        'executor_cli' => get_current_user(),
                        'executado_em' => now()->toIso8601String(),
                    ]
                );
            }

            $entregas->reconciliarPedidoEditado($pedido->fresh(), null);
        });

        $this->info($aplicaveis->count().' vinculo(s) corrigido(s) com sucesso.');

        return self::SUCCESS;
    }

    /** @return array{0:array<int,int>,1:list<string>} */
    private function interpretarMapeamentos(array $entradas): array
    {
        $mapeamentos = [];
        $erros = [];
        if ($entradas === []) {
            return [[], ['Informe ao menos uma correcao com --item=<pedido_item_id>:<variacao_id>.']];
        }

        foreach ($entradas as $entrada) {
            if (preg_match('/^(\d+):(\d+)$/', trim((string) $entrada), $partes) !== 1) {
                $erros[] = "Mapeamento invalido: {$entrada}. Use pedido_item_id:variacao_id.";

                continue;
            }
            $itemId = (int) $partes[1];
            $variacaoId = (int) $partes[2];
            if ($itemId <= 0 || $variacaoId <= 0 || isset($mapeamentos[$itemId])) {
                $erros[] = "Mapeamento duplicado ou invalido para o item {$itemId}.";

                continue;
            }
            $mapeamentos[$itemId] = $variacaoId;
        }

        return [$mapeamentos, $erros];
    }

    /** @param array<int,int> $mapeamentos */
    private function analisar(Pedido $pedido, array $mapeamentos): Collection
    {
        return collect($mapeamentos)->map(function (int $variacaoId, int $itemId) use ($pedido): array {
            $item = PedidoItem::query()->where('id_pedido', $pedido->id)->find($itemId);
            $variacao = ProdutoVariacao::query()->with('produto')->find($variacaoId);
            $anterior = (int) ($item?->id_variacao ?? 0);

            if (! $item) {
                return $this->linha($itemId, $anterior, $variacaoId, false, 'item_nao_pertence_ao_pedido');
            }
            if (! $variacao || $variacao->ativo === false) {
                return $this->linha($itemId, $anterior, $variacaoId, false, 'variacao_inexistente_ou_inativa');
            }
            if (! $this->variacaoCompativelComMedidasImportadas($itemId, $variacao)) {
                return $this->linha($itemId, $anterior, $variacaoId, false, 'variacao_incompativel_com_medidas_importadas');
            }
            if ($anterior === $variacaoId) {
                return $this->linha($itemId, $anterior, $variacaoId, false, 'sem_alteracao');
            }

            $entrega = ProdutoEntregaItem::query()->where('pedido_item_id', $itemId)->first();
            $temQuantidades = $entrega && max(
                (int) $entrega->quantidade_reservada,
                (int) $entrega->quantidade_recebida,
                (int) $entrega->quantidade_expedida,
                (int) $entrega->quantidade_entregue
            ) > 0;
            $temEventos = $entrega && ProdutoEntregaEvento::query()
                ->where('produto_entrega_item_id', $entrega->id)
                ->where('tipo_evento', '!=', ProdutoEntregaEvento::DEMANDA_CRIADA)
                ->exists();
            $temReservas = EstoqueReserva::query()->where('pedido_item_id', $itemId)->exists();
            $temMovimentos = EstoqueMovimentacao::query()->where('pedido_item_id', $itemId)->exists();

            if ($temQuantidades || $temEventos || $temReservas || $temMovimentos) {
                return $this->linha($itemId, $anterior, $variacaoId, false, 'bloqueado_por_historico_operacional');
            }

            return $this->linha($itemId, $anterior, $variacaoId, true, 'pronto_para_corrigir');
        })->values();
    }

    private function linha(int $itemId, int $anterior, int $nova, bool $aplicavel, string $resultado): array
    {
        return [
            'pedido_item_id' => $itemId,
            'variacao_anterior_id' => $anterior,
            'variacao_nova_id' => $nova,
            'aplicavel' => $aplicavel,
            'resultado' => $resultado,
        ];
    }

    private function variacaoCompativelComMedidasImportadas(int $itemId, ProdutoVariacao $variacao): bool
    {
        $registro = PedidoImportacaoItem::query()
            ->where('pedido_item_id', $itemId)
            ->latest('id')
            ->first();
        if (! $registro) {
            return true;
        }

        $dados = array_replace_recursive(
            (array) ($registro->dados_importados_json ?? []),
            (array) ($registro->dados_confirmados_json ?? [])
        );
        $fixos = (array) ($dados['fixos'] ?? []);
        $comparacoes = [
            'altura' => $variacao->produto?->altura,
            'largura' => $variacao->produto?->largura,
            'profundidade' => $variacao->produto?->profundidade,
            'comprimento' => $variacao->produto?->profundidade,
            'dimensao_1' => $variacao->dimensao_1,
            'dimensao_2' => $variacao->dimensao_2,
            'dimensao_3' => $variacao->dimensao_3,
        ];

        foreach ($comparacoes as $campo => $valorCadastro) {
            $valorImportado = $fixos[$campo] ?? $dados[$campo] ?? null;
            $importado = $this->numeroMedida($valorImportado);
            $cadastrado = $this->numeroMedida($valorCadastro);
            if ($importado !== null && $cadastrado !== null && abs($importado - $cadastrado) > 0.001) {
                return false;
            }
        }

        return true;
    }

    private function numeroMedida(mixed $valor): ?float
    {
        if (is_array($valor) || is_object($valor) || $valor === null) {
            return null;
        }
        $texto = str_replace(',', '.', trim((string) $valor));

        return preg_match('/-?\d+(?:\.\d+)?/', $texto, $matches) === 1
            ? (float) $matches[0]
            : null;
    }
}
