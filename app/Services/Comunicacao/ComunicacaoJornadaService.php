<?php

namespace App\Services\Comunicacao;

use App\Models\ComunicacaoJornada;
use App\Services\AuditoriaEventoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComunicacaoJornadaService
{
    public function __construct(private readonly AuditoriaEventoService $auditoria) {}

    public function salvar(?ComunicacaoJornada $jornada, array $dados): ComunicacaoJornada
    {
        $antes = $jornada?->load(['eventos', 'canais'])->toArray();

        $jornada = DB::transaction(function () use ($jornada, $dados): ComunicacaoJornada {
            $eventos = array_values(array_unique(array_filter(array_map(
                fn ($evento) => trim((string) $evento),
                (array) ($dados['eventos'] ?? [])
            ))));
            $canais = (array) ($dados['canais'] ?? []);
            unset($dados['eventos'], $dados['canais'], $dados['ativo']);

            if ($jornada) {
                $dados['versao'] = ((int) $jornada->versao) + 1;
                $dados['updated_by'] = auth()->id();
                $jornada->update($dados);
            } else {
                $dados['ativo'] = false;
                $dados['created_by'] = auth()->id();
                $dados['updated_by'] = auth()->id();
                $jornada = ComunicacaoJornada::query()->create($dados);
            }

            $jornada->eventos()->delete();
            foreach ($eventos as $evento) {
                $jornada->eventos()->create(['evento_codigo' => $evento]);
            }

            $jornada->canais()->delete();
            foreach ($canais as $canal) {
                $jornada->canais()->create([
                    'canal' => $canal['canal'],
                    'template_codigo' => trim((string) $canal['template_codigo']),
                    'ativo' => (bool) ($canal['ativo'] ?? false),
                ]);
            }

            return $jornada->fresh(['eventos', 'canais']);
        });

        $this->auditoria->registrar(
            module: 'comunicacao',
            action: $antes ? 'comunicacao.jornada.updated' : 'comunicacao.jornada.created',
            label: $antes ? 'Jornada de comunicação atualizada' : 'Jornada de comunicação criada',
            auditable: $jornada,
            metadata: ['antes' => $antes, 'depois' => $jornada->toArray()]
        );

        return $jornada;
    }

    public function ativar(ComunicacaoJornada $jornada, bool $ativo): ComunicacaoJornada
    {
        $jornada->load(['eventos', 'canais']);
        if ($ativo) {
            $this->validarAtivacao($jornada);
        }

        $antes = $jornada->ativo;
        $jornada->update([
            'ativo' => $ativo,
            'versao' => ((int) $jornada->versao) + 1,
            'updated_by' => auth()->id(),
        ]);

        $this->auditoria->registrar(
            module: 'comunicacao',
            action: $ativo ? 'comunicacao.jornada.activated' : 'comunicacao.jornada.deactivated',
            label: $ativo ? 'Jornada de comunicação ativada' : 'Jornada de comunicação desativada',
            auditable: $jornada,
            metadata: ['ativo_anterior' => $antes, 'ativo_atual' => $ativo]
        );

        return $jornada->fresh(['eventos', 'canais']);
    }

    private function validarAtivacao(ComunicacaoJornada $jornada): void
    {
        if ($jornada->canais->where('ativo', true)->isEmpty()) {
            throw ValidationException::withMessages(['canais' => 'Ative ao menos um canal com template.']);
        }

        if ($jornada->canais->where('ativo', true)->contains(fn ($canal) => trim((string) $canal->template_codigo) === '')) {
            throw ValidationException::withMessages(['canais' => 'Todo canal ativo deve possuir template.']);
        }

        if ($jornada->tipo === 'pedido' && $jornada->eventos->isEmpty()) {
            throw ValidationException::withMessages(['eventos' => 'Jornada de pedido exige ao menos um evento.']);
        }

        if ($jornada->tipo === 'cobranca') {
            $marcos = (array) data_get($jornada->agenda, 'marcos', []);
            if ($marcos === []) {
                throw ValidationException::withMessages(['agenda.marcos' => 'Jornada de cobrança exige marcos.']);
            }
        }
    }
}
