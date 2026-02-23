<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateE2EUserCommand extends Command
{
    protected $signature = 'sierra:e2e-user
        {--email=e2e@local.test : E-mail do usuÃ¡rio E2E}
        {--password=Senha@123 : Senha do usuÃ¡rio E2E}
        {--role=vendedor : Papel base (vendedor|admin|desenvolvedor|estoquista|financeiro)}
        {--nome=UsuÃ¡rio E2E ImportaÃ§Ã£o PDF : Nome do usuÃ¡rio E2E}';

    protected $description = 'Cria/atualiza usuÃ¡rio E2E local com permissÃµes para importar pedidos PDF pelo front.';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Este comando Ã© permitido apenas em ambiente local/testing.');
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');
        $role = mb_strtolower(trim((string) $this->option('role')));
        $nome = trim((string) $this->option('nome'));

        if ($email === '' || $password === '') {
            $this->error('Email e password sÃ£o obrigatÃ³rios.');
            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('A senha precisa ter ao menos 8 caracteres.');
            return self::FAILURE;
        }

        $perfilNomeBase = $this->resolverPerfilBase($role);
        if (!$perfilNomeBase) {
            $this->error('Role invÃ¡lida. Use: vendedor, admin, desenvolvedor, estoquista ou financeiro.');
            return self::FAILURE;
        }

        $requiredPerms = [
            'home.visualizar',
            'pedidos.visualizar',
            'pedidos.importar_pdf',
            'categorias.visualizar',
            'depositos.visualizar',
            'clientes.visualizar',
        ];

        $resultado = DB::transaction(function () use ($email, $password, $nome, $perfilNomeBase, $requiredPerms) {
            $usuario = DB::table('acesso_usuarios')
                ->where('email', $email)
                ->first();

            $now = now();

            if ($usuario) {
                DB::table('acesso_usuarios')
                    ->where('id', $usuario->id)
                    ->update([
                        'nome' => $nome,
                        'senha' => Hash::make($password),
                        'ativo' => 1,
                        'senha_alterada_em' => $now,
                        'updated_at' => $now,
                    ]);
                $usuarioId = (int) $usuario->id;
            } else {
                $usuarioId = (int) DB::table('acesso_usuarios')->insertGetId([
                    'nome' => $nome,
                    'email' => $email,
                    'senha' => Hash::make($password),
                    'ativo' => 1,
                    'senha_alterada_em' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $perfilBase = DB::table('acesso_perfis')
                ->where('nome', $perfilNomeBase)
                ->first();

            if (!$perfilBase) {
                throw new \RuntimeException("Perfil base nÃ£o encontrado: {$perfilNomeBase}");
            }

            $perfilE2E = DB::table('acesso_perfis')
                ->where('nome', 'E2E ImportaÃ§Ã£o PDF')
                ->first();

            if (!$perfilE2E) {
                $perfilE2EId = (int) DB::table('acesso_perfis')->insertGetId([
                    'nome' => 'E2E ImportaÃ§Ã£o PDF',
                    'descricao' => 'Perfil tÃ©cnico para testes E2E de importaÃ§Ã£o de pedidos PDF',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $perfilE2EId = (int) $perfilE2E->id;
            }

            $permsIds = DB::table('acesso_permissoes')
                ->whereIn('slug', $requiredPerms)
                ->pluck('id', 'slug');

            foreach ($requiredPerms as $slug) {
                if (!$permsIds->has($slug)) {
                    throw new \RuntimeException("PermissÃ£o obrigatÃ³ria nÃ£o encontrada: {$slug}");
                }
            }

            foreach ($permsIds as $permId) {
                DB::table('acesso_perfil_permissao')->updateOrInsert(
                    [
                        'id_perfil' => $perfilE2EId,
                        'id_permissao' => (int) $permId,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }

            foreach ([(int) $perfilBase->id, $perfilE2EId] as $perfilId) {
                DB::table('acesso_usuario_perfil')->updateOrInsert(
                    [
                        'id_usuario' => $usuarioId,
                        'id_perfil' => $perfilId,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }

            $clienteExistente = DB::table('clientes')
                ->where('email', 'e2e.cliente@local.test')
                ->first();

            if ($clienteExistente) {
                $clienteId = (int) $clienteExistente->id;
            } else {
                $clienteId = (int) DB::table('clientes')->insertGetId([
                    'nome' => 'Cliente E2E PDF',
                    'nome_fantasia' => null,
                    'documento' => '99999999000199',
                    'inscricao_estadual' => null,
                    'email' => 'e2e.cliente@local.test',
                    'telefone' => '91999999999',
                    'whatsapp' => null,
                    'tipo' => 'pj',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'usuario_id' => $usuarioId,
                'perfil_base' => $perfilBase->nome,
                'perfil_e2e_id' => $perfilE2EId,
                'cliente_id' => $clienteId,
                'permissoes' => $requiredPerms,
            ];
        });

        $this->info('UsuÃ¡rio E2E pronto.');
        $this->line('Email: ' . $email);
        $this->line('Senha: ' . $password);
        $this->line('Perfil base: ' . $resultado['perfil_base']);
        $this->line('Perfil adicional: E2E ImportaÃ§Ã£o PDF');
        $this->line('UsuÃ¡rio ID: ' . $resultado['usuario_id']);
        $this->line('Cliente E2E ID: ' . $resultado['cliente_id']);
        $this->line('PermissÃµes garantidas: ' . implode(', ', $resultado['permissoes']));

        return self::SUCCESS;
    }

    private function resolverPerfilBase(string $role): ?string
    {
        return match ($role) {
            'vendedor' => 'Vendedor',
            'admin', 'administrador' => 'Administrador',
            'desenvolvedor', 'dev' => 'Desenvolvedor',
            'estoquista' => 'Estoquista',
            'financeiro' => 'Financeiro',
            default => null,
        };
    }
}

