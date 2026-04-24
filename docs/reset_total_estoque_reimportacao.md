# Reset total do banco para reimportacao inicial

## Arquivos

- Script SQL: `scripts/sql/reset_total_estoque_reimportacao.sql`
- Bootstrap obrigatorio apos o reset: `php artisan app:setup-initial-data`
- Export do manifesto de imagens: `php artisan produtos:export-imagens-reset`
- Relink das imagens apos a carga inicial: `php artisan produtos:relink-imagens-reset {manifest_path}`

## O que fica preservado

- `migrations`
- `configuracoes`
- `acesso_usuarios`
- `acesso_perfis`
- `acesso_permissoes`
- `acesso_usuario_perfil`
- `acesso_perfil_permissao`
- `personal_access_tokens`
- `acesso_refresh_tokens`

Todo o restante do schema `estoque` e truncado dinamicamente via `information_schema.tables`.

## Protecao das imagens ja cadastradas

Antes do reset, exporte o manifesto completo das imagens e guarde tambem um backup fisico de
`storage/app/public/produtos`.

O comando de export gera sempre estes artefatos em
`storage/app/operations/reset-imagens/<timestamp>/`:

- `manifest.json`
- `summary.json`
- `pendencias.csv`

Exemplo:

```bash
cd /home/albertreis/fabio/gerenciador_estoque_api
php artisan produtos:export-imagens-reset
```

Se existir imagem cadastrada no banco sem arquivo correspondente no storage, o comando falha por
padrao e registra a pendencia em `pendencias.csv`. So use `--allow-missing-files` quando a equipe
decidir seguir com reenvio manual depois:

```bash
php artisan produtos:export-imagens-reset --allow-missing-files
```

Depois da reimportacao inicial, religue as imagens com o `manifest.json` exportado:

```bash
php artisan produtos:relink-imagens-reset storage/app/operations/reset-imagens/<timestamp>/manifest.json
```

Esse relink cria um novo diretório `relink-<timestamp>` ao lado do manifesto, contendo:

- `summary.json`
- `pendencias.csv`

## Ordem de execucao

1. Conecte no banco `estoque`.
2. Exporte o manifesto das imagens com `php artisan produtos:export-imagens-reset`.
3. Gere um backup tar.gz de `storage/app/public/produtos`.
4. Rode o script `scripts/sql/reset_total_estoque_reimportacao.sql`.
5. Rode o bootstrap obrigatorio da API de estoque:

```bash
cd /home/albertreis/fabio/gerenciador_estoque_api
php artisan app:setup-initial-data
```

Se estiver operando via container da API de estoque, o comando equivalente e:

```bash
docker compose exec -T gerenciador-estoque-api bash -lc 'cd /var/www && php artisan app:setup-initial-data'
```

6. Execute a nova importacao inicial ate staging/preview ou confirmacao final.
7. Religue as imagens usando o manifesto exportado.
8. Revise o `pendencias.csv` do relink antes de reabrir o ambiente.

## Exemplo de execucao do SQL

Use qualquer cliente MySQL conectado ao schema `estoque`. Exemplo generico:

```bash
mysql -h <host> -P <porta> -u <usuario> -p estoque < scripts/sql/reset_total_estoque_reimportacao.sql
```

## Validacao antes do reset

```sql
SELECT 'migrations' AS tabela, COUNT(*) AS total FROM migrations
UNION ALL
SELECT 'configuracoes', COUNT(*) FROM configuracoes
UNION ALL
SELECT 'acesso_usuarios', COUNT(*) FROM acesso_usuarios
UNION ALL
SELECT 'acesso_perfis', COUNT(*) FROM acesso_perfis
UNION ALL
SELECT 'acesso_permissoes', COUNT(*) FROM acesso_permissoes
UNION ALL
SELECT 'acesso_usuario_perfil', COUNT(*) FROM acesso_usuario_perfil
UNION ALL
SELECT 'acesso_perfil_permissao', COUNT(*) FROM acesso_perfil_permissao
UNION ALL
SELECT 'personal_access_tokens', COUNT(*) FROM personal_access_tokens
UNION ALL
SELECT 'acesso_refresh_tokens', COUNT(*) FROM acesso_refresh_tokens
UNION ALL
SELECT 'produto_imagens', COUNT(*) FROM produto_imagens
UNION ALL
SELECT 'produto_variacao_imagens', COUNT(*) FROM produto_variacao_imagens
UNION ALL
SELECT 'produtos', COUNT(*) FROM produtos
UNION ALL
SELECT 'produto_variacoes', COUNT(*) FROM produto_variacoes
UNION ALL
SELECT 'estoque', COUNT(*) FROM estoque
UNION ALL
SELECT 'pedidos', COUNT(*) FROM pedidos
UNION ALL
SELECT 'importacoes_normalizadas', COUNT(*) FROM importacoes_normalizadas;
```

## Validacao depois do reset e bootstrap

```sql
SELECT 'configuracoes' AS tabela, COUNT(*) AS total FROM configuracoes
UNION ALL
SELECT 'acesso_usuarios', COUNT(*) FROM acesso_usuarios
UNION ALL
SELECT 'acesso_perfis', COUNT(*) FROM acesso_perfis
UNION ALL
SELECT 'acesso_permissoes', COUNT(*) FROM acesso_permissoes
UNION ALL
SELECT 'acesso_usuario_perfil', COUNT(*) FROM acesso_usuario_perfil
UNION ALL
SELECT 'acesso_perfil_permissao', COUNT(*) FROM acesso_perfil_permissao
UNION ALL
SELECT 'personal_access_tokens', COUNT(*) FROM personal_access_tokens
UNION ALL
SELECT 'acesso_refresh_tokens', COUNT(*) FROM acesso_refresh_tokens
UNION ALL
SELECT 'produto_imagens', COUNT(*) FROM produto_imagens
UNION ALL
SELECT 'produto_variacao_imagens', COUNT(*) FROM produto_variacao_imagens
UNION ALL
SELECT 'produtos', COUNT(*) FROM produtos
UNION ALL
SELECT 'produto_variacoes', COUNT(*) FROM produto_variacoes
UNION ALL
SELECT 'estoque', COUNT(*) FROM estoque
UNION ALL
SELECT 'pedidos', COUNT(*) FROM pedidos
UNION ALL
SELECT 'importacoes_normalizadas', COUNT(*) FROM importacoes_normalizadas
UNION ALL
SELECT 'feriados', COUNT(*) FROM feriados
UNION ALL
SELECT 'formas_pagamento', COUNT(*) FROM formas_pagamento
UNION ALL
SELECT 'outlet_motivos', COUNT(*) FROM outlet_motivos
UNION ALL
SELECT 'outlet_formas_pagamento', COUNT(*) FROM outlet_formas_pagamento
UNION ALL
SELECT 'assistencia_defeitos', COUNT(*) FROM assistencia_defeitos
UNION ALL
SELECT 'areas_estoque', COUNT(*) FROM areas_estoque
UNION ALL
SELECT 'localizacao_dimensoes', COUNT(*) FROM localizacao_dimensoes;
```

Validacao especifica do deposito sistemico:

```sql
SELECT id, nome, endereco, created_at, updated_at
FROM depositos
WHERE nome LIKE 'ASSIST%';
```

## Validacao funcional manual

- Confirmar que um usuario existente ainda consegue autenticar.
- Confirmar que o usuario autenticado acessa normalmente o modulo de estoque.
- Validar uma nova importacao com `modo_carga_inicial = true` ate pelo menos staging e preview.
- Conferir o `summary.json` e o `pendencias.csv` do relink de imagens.
- Validar manualmente uma amostra de imagens reanexadas em produto e variacao.
- Limpar cache da aplicacao somente se houver comportamento inconsistente apos o bootstrap.
