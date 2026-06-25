<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\PedidoStatusDefinicao;
use App\Models\PedidoStatusFluxoItem;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PedidoStatusFluxoService
{
    public const TIPO_VENDA = 'venda';
    public const TIPO_REPOSICAO = 'reposicao';
    public const TIPO_CONSIGNACAO = 'consignacao';

    public const TIPOS_FLUXO = [
        self::TIPO_VENDA,
        self::TIPO_REPOSICAO,
        self::TIPO_CONSIGNACAO,
    ];

    public const STATUS_PREVISAO_EDITAVEIS_LEGADO = [
        'previsao_embarque_fabrica',
        'embarque_fabrica',
        'previsao_entrega_estoque',
        'entrega_estoque',
        'finalizado',
    ];

    private static ?Collection $catalogoCache = null;

    public function limparCache(): void
    {
        self::$catalogoCache = null;
    }

    public function catalogo(bool $incluirInativos = false): Collection
    {
        $catalogo = $this->catalogoCompleto();

        if ($incluirInativos) {
            return $catalogo->values();
        }

        return $catalogo
            ->filter(fn (PedidoStatusDefinicao $status) => (bool) $status->ativo)
            ->values();
    }

    public function catalogoMap(bool $incluirInativos = true): Collection
    {
        return $this->catalogo($incluirInativos)->keyBy('codigo');
    }

    public function statusMeta(?string $codigo): array
    {
        $codigo = $this->normalizarStatus($codigo);

        if (!$codigo) {
            return [
                'codigo' => null,
                'nome' => 'Sem status',
                'label' => 'Sem status',
                'cor' => '#adb5bd',
                'severidade' => 'secondary',
                'color' => 'secondary',
                'icone' => 'pi pi-info-circle',
                'ativo' => false,
                'sistema' => false,
                'protegido' => false,
                'papel_operacional' => null,
            ];
        }

        $status = $this->catalogoMap(true)->get($codigo);

        if (!$status) {
            $legacy = self::statusLegados()[$codigo] ?? null;

            if ($legacy) {
                $status = new PedidoStatusDefinicao($legacy + [
                    'ativo' => true,
                    'sistema' => true,
                    'protegido' => false,
                    'papel_operacional' => $codigo,
                ]);
            }
        }

        if ($status) {
            return [
                'codigo' => $status->codigo,
                'nome' => $status->nome,
                'label' => $status->nome,
                'descricao' => $status->descricao,
                'cor' => $status->cor ?: '#adb5bd',
                'severidade' => $status->severidade ?: 'secondary',
                'color' => $status->severidade ?: 'secondary',
                'icone' => $status->icone ?: 'pi pi-info-circle',
                'ativo' => (bool) $status->ativo,
                'sistema' => (bool) $status->sistema,
                'protegido' => (bool) $status->protegido,
                'papel_operacional' => $status->papel_operacional,
            ];
        }

        $label = PedidoStatus::tryFrom($codigo)?->label()
            ?? ucfirst(str_replace('_', ' ', $codigo));

        return [
            'codigo' => $codigo,
            'nome' => $label,
            'label' => $label,
            'descricao' => null,
            'cor' => '#adb5bd',
            'severidade' => 'secondary',
            'color' => 'secondary',
            'icone' => 'pi pi-info-circle',
            'ativo' => false,
            'sistema' => false,
            'protegido' => false,
            'papel_operacional' => null,
        ];
    }

    public function tipoFluxo(Pedido $pedido): string
    {
        $statusAtual = $this->statusAtualCodigo($pedido);

        if (in_array($statusAtual, [PedidoStatus::CONSIGNADO->value, PedidoStatus::DEVOLUCAO_CONSIGNACAO->value], true)) {
            return self::TIPO_CONSIGNACAO;
        }

        if ($pedido->exists && ($pedido->relationLoaded('consignacoes')
            ? $pedido->consignacoes->isNotEmpty()
            : $pedido->consignacoes()->exists())) {
            return self::TIPO_CONSIGNACAO;
        }

        if (method_exists($pedido, 'isReposicao') && $pedido->isReposicao()) {
            return self::TIPO_REPOSICAO;
        }

        return self::TIPO_VENDA;
    }

    public function tipoFluxoPorStatus(?string $statusAtual): string
    {
        if (in_array($statusAtual, [PedidoStatus::CONSIGNADO->value, PedidoStatus::DEVOLUCAO_CONSIGNACAO->value], true)) {
            return self::TIPO_CONSIGNACAO;
        }

        return self::TIPO_VENDA;
    }

    public function fluxoDetalhado(Pedido $pedido, bool $somenteAtivos = true): Collection
    {
        return $this->fluxoDetalhadoPorTipo($this->tipoFluxo($pedido), $somenteAtivos);
    }

    public function fluxoDetalhadoPorTipo(string $tipo, bool $somenteAtivos = true): Collection
    {
        $tipo = $this->normalizarTipoFluxo($tipo);

        if ($this->tabelasConfiguraveisDisponiveis()) {
            $query = PedidoStatusFluxoItem::query()
                ->with('statusDefinicao')
                ->where('tipo_fluxo', $tipo)
                ->orderBy('ordem');

            if ($somenteAtivos) {
                $query->where('ativo', true)
                    ->whereHas('statusDefinicao', fn ($q) => $q->where('ativo', true));
            }

            $itens = $query->get();

            if ($itens->isNotEmpty()) {
                return $itens
                    ->filter(fn (PedidoStatusFluxoItem $item) => $item->statusDefinicao !== null)
                    ->map(fn (PedidoStatusFluxoItem $item) => $this->itemFluxoParaArray($item))
                    ->values();
            }
        }

        return $this->fluxoLegadoDetalhado($tipo, $somenteAtivos);
    }

    public function codigosFluxo(Pedido $pedido, bool $somenteAtivos = true): array
    {
        return $this->fluxoDetalhado($pedido, $somenteAtivos)
            ->pluck('codigo')
            ->values()
            ->all();
    }

    public function opcoesDisponiveis(Pedido $pedido): Collection
    {
        $registrados = $pedido->historicoStatus()
            ->pluck('status')
            ->map(fn ($status) => $this->normalizarStatus($status))
            ->filter()
            ->unique()
            ->values();

        return $this->fluxoDetalhado($pedido)
            ->reject(fn (array $item) => $registrados->contains($item['codigo']))
            ->map(fn (array $item) => $this->opcaoParaArray($item))
            ->values();
    }

    public function proximoStatusDetalhado(Pedido $pedido): ?array
    {
        $fluxo = $this->fluxoDetalhado($pedido);
        $statusAtual = $this->statusAtualCodigo($pedido);

        if ($fluxo->isEmpty()) {
            return null;
        }

        if (!$statusAtual) {
            return $fluxo->first();
        }

        $codigos = $fluxo->pluck('codigo')->values()->all();
        $indiceAtual = array_search($statusAtual, $codigos, true);

        if ($indiceAtual !== false) {
            return $fluxo->get($indiceAtual + 1);
        }

        $registrados = $pedido->historicoStatus()
            ->pluck('status')
            ->map(fn ($status) => $this->normalizarStatus($status))
            ->filter()
            ->unique();

        return $fluxo
            ->first(fn (array $item) => !$registrados->contains($item['codigo']));
    }

    public function proximoStatusCodigo(Pedido $pedido): ?string
    {
        return $this->proximoStatusDetalhado($pedido)['codigo'] ?? null;
    }

    public function statusAtualCodigo(Pedido $pedido): ?string
    {
        $statusAtual = $pedido->statusAtual;

        if (!$statusAtual) {
            return null;
        }

        return $this->normalizarStatus(
            $statusAtual->getRawOriginal('status') ?: $statusAtual->status
        );
    }

    public function previsoes(Pedido $pedido, array $previsoesManuais = []): array
    {
        $datas = $pedido->historicoStatus()
            ->get(['status', 'data_status'])
            ->mapWithKeys(fn ($item) => [
                (string) $item->getRawOriginal('status') => $item->data_status,
            ])
            ->toArray();

        return $this->previsoesPorTipo($this->tipoFluxo($pedido), $datas, $previsoesManuais);
    }

    public function previsoesPorTipo(string $tipo, array $datas, array $previsoesManuais = []): array
    {
        $fluxo = $this->fluxoDetalhadoPorTipo($tipo, false)->values();
        $previsoes = [];

        foreach ($fluxo as $indice => $item) {
            if ($indice === 0) {
                continue;
            }

            $status = $item['codigo'];
            $statusAnterior = $fluxo[$indice - 1]['codigo'] ?? null;
            $prazoDias = $item['prazo_dias'];

            if ($statusAnterior && $prazoDias !== null && isset($datas[$statusAnterior]) && $datas[$statusAnterior]) {
                $previsoes[$status] = Carbon::parse($datas[$statusAnterior])->addDays((int) $prazoDias);
            } else {
                $previsoes[$status] = null;
            }
        }

        foreach ($previsoesManuais as $status => $dataPrevista) {
            if ($dataPrevista) {
                $previsoes[$status] = Carbon::parse($dataPrevista);
            }
        }

        return $previsoes;
    }

    public function previsaoProximoStatus(Pedido $pedido): ?Carbon
    {
        $proximo = $this->proximoStatusCodigo($pedido);

        if (!$proximo) {
            return null;
        }

        $previsoesManuais = $pedido->relationLoaded('statusPrevisoes')
            ? $pedido->statusPrevisoes
                ->filter(fn ($previsao) => $previsao->data_prevista)
                ->mapWithKeys(fn ($previsao) => [
                    (string) $this->normalizarStatus($previsao->getRawOriginal('status') ?: $previsao->status) => $previsao->data_prevista,
                ])
                ->toArray()
            : $pedido->statusPrevisoes()
                ->whereNotNull('data_prevista')
                ->pluck('data_prevista', 'status')
                ->toArray();

        $previsoes = $this->previsoes($pedido, $previsoesManuais);
        $data = $previsoes[$proximo] ?? null;

        return $data ? Carbon::parse($data) : null;
    }

    public function exigePrevisaoManual(Pedido $pedido, string $status): bool
    {
        $status = $this->normalizarStatus($status);

        $item = $this->fluxoDetalhado($pedido, false)
            ->first(fn (array $item) => $item['codigo'] === $status);

        if ($item) {
            return (bool) $item['exige_previsao_manual'];
        }

        return in_array($status, self::STATUS_PREVISAO_EDITAVEIS_LEGADO, true);
    }

    public function statusUsado(string $codigo): bool
    {
        return \App\Models\PedidoStatusHistorico::query()->where('status', $codigo)->exists()
            || \App\Models\PedidoStatusPrevisao::query()->where('status', $codigo)->exists();
    }

    public function normalizarStatus(mixed $status): ?string
    {
        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        if ($status === null || $status === '') {
            return null;
        }

        return (string) $status;
    }

    public function normalizarTipoFluxo(string $tipo): string
    {
        return in_array($tipo, self::TIPOS_FLUXO, true) ? $tipo : self::TIPO_VENDA;
    }

    public function tabelasConfiguraveisDisponiveis(): bool
    {
        return Schema::hasTable('pedido_statuses') && Schema::hasTable('pedido_status_fluxo_itens');
    }

    private function catalogoCompleto(): Collection
    {
        if (self::$catalogoCache instanceof Collection) {
            return self::$catalogoCache;
        }

        if ($this->tabelasConfiguraveisDisponiveis()) {
            $catalogo = PedidoStatusDefinicao::query()
                ->orderBy('nome')
                ->get();

            if ($catalogo->isNotEmpty()) {
                return self::$catalogoCache = $catalogo;
            }
        }

        return self::$catalogoCache = collect(self::statusLegados())
            ->map(fn (array $status) => new PedidoStatusDefinicao($status + [
                'ativo' => true,
                'sistema' => true,
                'protegido' => false,
                'papel_operacional' => $status['codigo'],
            ]))
            ->values();
    }

    private function itemFluxoParaArray(PedidoStatusFluxoItem $item): array
    {
        $meta = $this->statusMeta($item->statusDefinicao->codigo);

        return [
            ...$meta,
            'id' => $item->id,
            'status_id' => $item->statusDefinicao->id,
            'ordem' => (int) $item->ordem,
            'prazo_dias' => $item->prazo_dias,
            'exige_previsao_manual' => (bool) $item->exige_previsao_manual,
            'ativo_fluxo' => (bool) $item->ativo,
        ];
    }

    private function opcaoParaArray(array $item): array
    {
        return [
            'value' => $item['codigo'],
            'label' => $item['label'],
            'codigo' => $item['codigo'],
            'nome' => $item['nome'],
            'cor' => $item['cor'],
            'color' => $item['color'],
            'severidade' => $item['severidade'],
            'icone' => $item['icone'],
            'ordem' => $item['ordem'],
            'prazo_dias' => $item['prazo_dias'],
            'exige_previsao_manual' => (bool) $item['exige_previsao_manual'],
        ];
    }

    private function fluxoLegadoDetalhado(string $tipo, bool $somenteAtivos): Collection
    {
        $fluxos = self::fluxosLegados();
        $itens = $fluxos[$tipo] ?? $fluxos[self::TIPO_VENDA];

        return collect($itens)
            ->map(function (array $item, int $indice) {
                $meta = $this->statusMeta($item['codigo']);

                return [
                    ...$meta,
                    'id' => null,
                    'status_id' => null,
                    'ordem' => $indice + 1,
                    'prazo_dias' => $item['prazo_dias'] ?? null,
                    'exige_previsao_manual' => (bool) ($item['exige_previsao_manual'] ?? false),
                    'ativo_fluxo' => true,
                ];
            })
            ->when($somenteAtivos, fn (Collection $itens) => $itens->filter(fn (array $item) => (bool) $item['ativo']))
            ->values();
    }

    public static function statusLegados(): array
    {
        return [
            'pedido_criado' => ['codigo' => 'pedido_criado', 'nome' => 'Pedido Criado', 'cor' => '#007bff', 'severidade' => 'secondary', 'icone' => 'pi pi-file', 'protegido' => true],
            'pedido_enviado_fabrica' => ['codigo' => 'pedido_enviado_fabrica', 'nome' => 'Enviado a Fabrica', 'cor' => '#0dcaf0', 'severidade' => 'info', 'icone' => 'pi pi-send', 'protegido' => false],
            'nota_emitida' => ['codigo' => 'nota_emitida', 'nome' => 'Nota Emitida', 'cor' => '#20c997', 'severidade' => 'success', 'icone' => 'pi pi-file-edit', 'protegido' => false],
            'previsao_embarque_fabrica' => ['codigo' => 'previsao_embarque_fabrica', 'nome' => 'Previsao de Embarque', 'cor' => '#ffc107', 'severidade' => 'warning', 'icone' => 'pi pi-calendar-clock', 'protegido' => false],
            'embarque_fabrica' => ['codigo' => 'embarque_fabrica', 'nome' => 'Embarque da Fabrica', 'cor' => '#17a2b8', 'severidade' => 'info', 'icone' => 'pi pi-truck', 'protegido' => false],
            'nota_recebida_compra' => ['codigo' => 'nota_recebida_compra', 'nome' => 'Nota Recebida (Compra)', 'cor' => '#6610f2', 'severidade' => 'success', 'icone' => 'pi pi-download', 'protegido' => false],
            'previsao_entrega_estoque' => ['codigo' => 'previsao_entrega_estoque', 'nome' => 'Previsao de Entrega ao Estoque', 'cor' => '#ffc107', 'severidade' => 'warning', 'icone' => 'pi pi-calendar-clock', 'protegido' => false],
            'entrega_estoque' => ['codigo' => 'entrega_estoque', 'nome' => 'Entrega ao Estoque', 'cor' => '#6f42c1', 'severidade' => 'success', 'icone' => 'pi pi-box', 'protegido' => true],
            'previsao_envio_cliente' => ['codigo' => 'previsao_envio_cliente', 'nome' => 'Previsao de Envio ao Cliente', 'cor' => '#ffc107', 'severidade' => 'warning', 'icone' => 'pi pi-calendar-minus', 'protegido' => false],
            'envio_cliente' => ['codigo' => 'envio_cliente', 'nome' => 'Envio ao Cliente', 'cor' => '#fd7e14', 'severidade' => 'warning', 'icone' => 'pi pi-send', 'protegido' => true],
            'entrega_cliente' => ['codigo' => 'entrega_cliente', 'nome' => 'Entrega ao Cliente', 'cor' => '#198754', 'severidade' => 'success', 'icone' => 'pi pi-home', 'protegido' => true],
            'consignado' => ['codigo' => 'consignado', 'nome' => 'Consignado', 'cor' => '#0dcaf0', 'severidade' => 'info', 'icone' => 'pi pi-briefcase', 'protegido' => true],
            'devolucao_consignacao' => ['codigo' => 'devolucao_consignacao', 'nome' => 'Devolucao de Consignacao', 'cor' => '#dc3545', 'severidade' => 'danger', 'icone' => 'pi pi-undo', 'protegido' => true],
            'finalizado' => ['codigo' => 'finalizado', 'nome' => 'Finalizado', 'cor' => '#198754', 'severidade' => 'success', 'icone' => 'pi pi-check-circle', 'protegido' => true],
            'cancelado' => ['codigo' => 'cancelado', 'nome' => 'Cancelado', 'cor' => '#dc3545', 'severidade' => 'danger', 'icone' => 'pi pi-times-circle', 'protegido' => true],
        ];
    }

    public static function fluxosLegados(): array
    {
        return [
            self::TIPO_VENDA => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'pedido_enviado_fabrica', 'prazo_dias' => 5],
                ['codigo' => 'nota_emitida'],
                ['codigo' => 'previsao_embarque_fabrica', 'prazo_dias' => 7, 'exige_previsao_manual' => true],
                ['codigo' => 'embarque_fabrica', 'exige_previsao_manual' => true],
                ['codigo' => 'nota_recebida_compra'],
                ['codigo' => 'previsao_entrega_estoque', 'prazo_dias' => 7, 'exige_previsao_manual' => true],
                ['codigo' => 'entrega_estoque', 'exige_previsao_manual' => true],
                ['codigo' => 'previsao_envio_cliente', 'prazo_dias' => 3],
                ['codigo' => 'envio_cliente'],
                ['codigo' => 'entrega_cliente', 'prazo_dias' => 3],
                ['codigo' => 'finalizado', 'exige_previsao_manual' => true],
            ],
            self::TIPO_REPOSICAO => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'entrega_estoque'],
                ['codigo' => 'envio_cliente'],
                ['codigo' => 'entrega_cliente', 'prazo_dias' => 3],
                ['codigo' => 'finalizado', 'exige_previsao_manual' => true],
            ],
            self::TIPO_CONSIGNACAO => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'consignado'],
                ['codigo' => 'devolucao_consignacao', 'prazo_dias' => 15],
                ['codigo' => 'finalizado', 'exige_previsao_manual' => true],
            ],
        ];
    }
}
