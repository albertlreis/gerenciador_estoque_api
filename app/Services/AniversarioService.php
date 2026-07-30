<?php

namespace App\Services;

use App\Helpers\AuthHelper;
use App\Models\Cliente;
use App\Models\Parceiro;
use App\Support\Dates\BirthdayDateNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AniversarioService
{
    public function listar(array $filtros = []): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $hoje = CarbonImmutable::now($timezone)->startOfDay();
        $escopo = $filtros['escopo'] ?? 'dia';
        $mesSelecionado = (int) ($filtros['mes'] ?? $hoje->month);

        $aniversariantes = $this->clientes($hoje)
            ->concat($this->parceiros($hoje))
            ->filter(function (array $item) use ($escopo, $mesSelecionado, $hoje): bool {
                if ($escopo === 'dia') {
                    return $item['dias_para_aniversario'] === 0;
                }

                if ($escopo === 'semana') {
                    return $item['dias_para_aniversario'] >= 0 && $item['dias_para_aniversario'] <= 6;
                }

                return (int) $this->resolveOccurrenceForYear($item['birth_date'], $hoje->year)->month === $mesSelecionado;
            })
            ->sort(function (array $left, array $right) use ($escopo, $hoje, $mesSelecionado): int {
                if ($escopo === 'mes') {
                    $leftDate = $this->resolveOccurrenceForYear($left['birth_date'], $hoje->year);
                    $rightDate = $this->resolveOccurrenceForYear($right['birth_date'], $hoje->year);

                    if ($leftDate->month !== $mesSelecionado && $rightDate->month !== $mesSelecionado) {
                        return strcmp($left['nome'], $right['nome']);
                    }

                    return [$leftDate->day, $left['nome']] <=> [$rightDate->day, $right['nome']];
                }

                return [$left['dias_para_aniversario'], $left['nome']] <=> [$right['dias_para_aniversario'], $right['nome']];
            })
            ->values()
            ->map(fn (array $item) => $this->serializeItem($item))
            ->all();

        return [
            'data' => $aniversariantes,
            'meta' => [
                'escopo' => $escopo,
                'mes' => $escopo === 'mes' ? $mesSelecionado : null,
                'referencia' => $hoje->toDateString(),
                'timezone' => $timezone,
                'regra_29_02' => 'Em anos não bissextos, aniversários em 29/02 são considerados em 28/02.',
            ],
        ];
    }

    private function clientes(CarbonImmutable $hoje): Collection
    {
        $podeVerContato = AuthHelper::hasPermissao('clientes.visualizar');

        return Cliente::query()
            ->whereNotNull('data_nascimento')
            ->orderBy('nome')
            ->get()
            ->map(fn (Cliente $cliente) => $this->makeItem(
                tipo: 'cliente',
                id: (int) $cliente->id,
                nome: (string) $cliente->nome,
                email: $podeVerContato ? $cliente->email : null,
                telefone: $podeVerContato ? $cliente->telefone : null,
                nascimento: $cliente->data_nascimento,
                hoje: $hoje,
            ))
            ->filter();
    }

    private function parceiros(CarbonImmutable $hoje): Collection
    {
        $podeVerContato = AuthHelper::hasPermissao('parceiros.visualizar');

        return Parceiro::query()
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->whereNotNull('data_nascimento')
            ->orderBy('nome')
            ->get()
            ->map(fn (Parceiro $parceiro) => $this->makeItem(
                tipo: 'parceiro',
                id: (int) $parceiro->id,
                nome: (string) $parceiro->nome,
                email: $podeVerContato ? $parceiro->email : null,
                telefone: $podeVerContato ? $parceiro->telefone : null,
                nascimento: $parceiro->data_nascimento,
                hoje: $hoje,
            ))
            ->filter();
    }

    private function makeItem(
        string $tipo,
        int $id,
        string $nome,
        ?string $email,
        ?string $telefone,
        mixed $nascimento,
        CarbonImmutable $hoje,
    ): ?array {
        $birthDate = BirthdayDateNormalizer::parse($nascimento);
        if ($birthDate === null) {
            return null;
        }

        $proximaOcorrencia = $this->resolveNextOccurrence($birthDate, $hoje);

        return [
            'tipo' => $tipo,
            'tipo_label' => $tipo === 'cliente' ? 'Cliente' : 'Parceiro',
            'id' => $id,
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone,
            'birth_date' => $birthDate,
            'idade' => $birthDate->diffInYears($hoje),
            'nova_idade' => $birthDate->diffInYears($proximaOcorrencia),
            'proxima_ocorrencia' => $proximaOcorrencia,
            'dias_para_aniversario' => $hoje->diffInDays($proximaOcorrencia, false),
        ];
    }

    private function serializeItem(array $item): array
    {
        /** @var CarbonImmutable $birthDate */
        $birthDate = $item['birth_date'];
        /** @var CarbonImmutable $nextOccurrence */
        $nextOccurrence = $item['proxima_ocorrencia'];

        return [
            'tipo' => $item['tipo'],
            'tipo_label' => $item['tipo_label'],
            'id' => $item['id'],
            'nome' => $item['nome'],
            'email' => $item['email'],
            'telefone' => $item['telefone'],
            'data_nascimento' => $birthDate->toDateString(),
            'data_nascimento_formatada' => $birthDate->format('d/m/Y'),
            'idade' => $item['idade'],
            'nova_idade' => $item['nova_idade'],
            'proxima_ocorrencia' => $nextOccurrence->toDateString(),
            'proxima_ocorrencia_formatada' => $nextOccurrence->format('d/m/Y'),
            'dias_para_aniversario' => $item['dias_para_aniversario'],
        ];
    }

    private function resolveNextOccurrence(CarbonImmutable $birthDate, CarbonImmutable $reference): CarbonImmutable
    {
        $occurrence = $this->resolveOccurrenceForYear($birthDate, $reference->year);

        if ($occurrence->lessThan($reference)) {
            return $this->resolveOccurrenceForYear($birthDate, $reference->year + 1);
        }

        return $occurrence;
    }

    private function resolveOccurrenceForYear(CarbonImmutable $birthDate, int $year): CarbonImmutable
    {
        // Regra de negócio: em anos não bissextos o aniversário de 29/02 cai em 28/02.
        if ($birthDate->month === 2 && $birthDate->day === 29 && !CarbonImmutable::create($year, 1, 1)->isLeapYear()) {
            return CarbonImmutable::create($year, 2, 28)->startOfDay();
        }

        return CarbonImmutable::create($year, $birthDate->month, $birthDate->day)->startOfDay();
    }
}
