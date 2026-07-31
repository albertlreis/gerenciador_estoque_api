# Reconciliação controlada de pedidos entregues

O comando abaixo nunca aplica correções sem manifesto, conferência física e confirmação literal do lote. Execute primeiro em HML e gere novamente o manifesto em cada ambiente.

## 1. Gerar o manifesto

```bash
php artisan pedidos:reconciliar-entregas --exportar=/tmp/reconciliacao-entregas.csv
```

Preencha no CSV:

- `saldo_fisico_confirmado` para cada referência e depósito;
- `confirmacao_documental` e `confirmacao_fisica` com `SIM`;
- `evidencia` e `justificativa` com pelo menos dez caracteres;
- revise `acao`: `BAIXAR_E_ENTREGAR`, `DOCUMENTAR_SEM_BAIXA` ou `AJUSTAR_SALDO`.

Linhas da mesma variação e depósito devem repetir a mesma contagem física. A diferença entre o saldo capturado e o saldo físico precisa corresponder exatamente à soma das baixas do grupo.

## 2. Validar sem escrever

```bash
php artisan pedidos:reconciliar-entregas \
  --manifesto=/tmp/reconciliacao-entregas.csv \
  --usuario=ID_DO_RESPONSAVEL \
  --dry-run
```

O dry-run bloqueia saldo alterado, vínculo ambíguo, pedido fora de estado terminal, depósito divergente e reserva de terceiros.

## 3. Aplicar

```bash
php artisan pedidos:reconciliar-entregas \
  --manifesto=/tmp/reconciliacao-entregas.csv \
  --usuario=ID_DO_RESPONSAVEL \
  --execute \
  --confirmar=UUID_DO_LOTE
```

A aplicação é atômica e idempotente. Ela preserva o status final dos pedidos e registra os detalhes, movimentos e eventos criados por cada linha.

## 4. Rollback controlado

```bash
php artisan pedidos:reconciliar-entregas \
  --rollback=UUID_DO_LOTE \
  --usuario=ID_DO_RESPONSAVEL \
  --execute \
  --confirmar=UUID_DO_LOTE
```

O rollback cria estornos; não apaga histórico. Para reaplicar após rollback, gere um novo manifesto com outro lote.
