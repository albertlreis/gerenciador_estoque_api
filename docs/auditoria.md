# Módulo de Auditoria

## Fase 0 - Diagnóstico de IDs e padrão adotado

### Fonte do diagnóstico
- Leitura das migrations no repositório `gerenciador_estoque_api`.
- Leitura das migrations e fluxo de auth no repositório `autenticacao_api`.

### Tabelas críticas e tipo de PK

| Entidade | Tabela | Migration | PK identificada |
|---|---|---|---|
| Produto | `produtos` | `2025_04_08_073413_create_produtos_table.php` | `increments('id')` (`unsigned int`) |
| Variação de produto | `produto_variacoes` | `2025_04_08_074008_create_produto_variacoes_table.php` | `increments('id')` (`unsigned int`) |
| Pedido | `pedidos` | `2025_04_08_074915_create_pedidos_table.php` | `increments('id')` (`unsigned int`) |
| Item de pedido | `pedido_itens` | `2025_04_08_075019_create_pedido_itens_table.php` | `increments('id')` (`unsigned int`) |
| Movimentação de estoque | `estoque_movimentacoes` | `2025_04_08_084458_create_estoque_movimentacoes_table.php` | `increments('id')` (`unsigned int`) |
| Conta a pagar | `contas_pagar` | `2025_10_17_061821_create_contas_pagar_table.php` | `id()` (`unsigned bigint`) |
| Conta a receber | `contas_receber` | `2025_10_30_065019_create_contas_receber_table.php` | `id()` (`unsigned bigint`) |
| Lançamento financeiro | `lancamentos_financeiros` | `2025_12_23_046347_create_lancamentos_financeiros_table.php` | `id()` (`unsigned bigint`) |
| Usuário (ator) | `acesso_usuarios` | `autenticacao_api/2025_04_14_000001_create_acesso_usuarios_table.php` | `id()` (`unsigned bigint`) |

### Decisão para `actor_id` e `auditable_id`

**Padrão adotado**: `unsignedBigInteger`.

Justificativa:
- Não foi identificado PK UUID/string nas entidades auditadas.
- Há mistura de `increments` (unsigned int) e `id()` (unsigned bigint).
- `unsignedBigInteger` cobre ambos os casos numéricos sem perda e evita limitação futura.

## Ator e autenticação

- O `gerenciador_estoque_api` usa `auth:sanctum` (guard `api`) com modelo de usuário em `acesso_usuarios`.
- O ator é resolvido por `auth()->user()`, com:
  - `actor_id` = `auth()->id()`
  - `actor_name` = `auth()->user()->nome`
- O `autenticacao_api` já expõe `GET /api/v1/auth/me` com `id`, `nome`, `perfis` e `permissoes`.
- Não foi necessário ajuste no `autenticacao_api` para obter ID/nome do ator.

## Campos sensíveis (sanitização)

Campos removidos da trilha de mudanças/metadata por padrão:
- `password`, `senha`
- `token`, `access_token`, `refresh_token`
- `secret`, `client_secret`
- `authorization`, `api_key`, `x_api_key`

Campos sensíveis por entidade podem ser estendidos via configuração da auditoria.

## Como usar o logger (resumo)

O módulo expõe `App\Support\Audit\AuditLogger` com:
- `logCreate($model, $module, $label, array $metadata = [])`
- `logUpdate($model, $module, $label, array $metadata = [], array $ignoreFields = [])`
- `logDelete($model, $module, $label, array $metadata = [])`
- `logCustom($auditableType, $auditableId, $module, $action, $label, $changes = [], $metadata = [])`

Regras:
- Auditoria é append-only (somente insert).
- Em fluxos transacionais, o log é executado dentro da mesma transação.
- Sem usuário autenticado, o ator é `SYSTEM`.
