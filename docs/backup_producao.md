# Backup de producao Sierra

Este procedimento configura backup diario do ambiente de producao Sierra:

- banco MySQL unico: `sierra`;
- container MySQL: `mysql_server`;
- arquivos persistentes do estoque: `/home/docker/acadsoft/sierra-estoque/html/storage/app`;
- arquivos persistentes da autenticacao: `/home/docker/acadsoft/sierra-auth/html/storage/app`;
- `.env` das duas APIs em pacote separado;
- destino remoto criptografado: Google Drive via `rclone crypt`.

O host de producao usa UTC. A rotina roda as `05:30 UTC`, equivalente a `02:30 America/Sao_Paulo`.

## 1. Instalar o rclone

No servidor:

```bash
curl https://rclone.org/install.sh | sudo bash
rclone version
```

Configure como `root`, pois o cron tambem roda como `root`:

```bash
sudo -i
rclone config
```

## 2. Criar o remote Google Drive

No wizard do `rclone config`:

1. Escolha `n` para criar um novo remote.
2. Nome: `sierra_gdrive`.
3. Storage: `drive`.
4. Client ID e Client Secret: deixe vazio, a menos que exista um app OAuth proprio.
5. Scope: escolha acesso suficiente para criar e gerenciar os arquivos de backup.
6. Root folder ID: deixe vazio, salvo se o Drive de destino exigir uma pasta especifica.
7. Service account file: deixe vazio.
8. Advanced config: `n`.
9. Auto config: use `y` se houver browser disponivel no servidor; caso contrario use `n` e conclua a autenticacao em outra maquina.
10. Confirme o remote.

Valide:

```bash
rclone lsd sierra_gdrive:
```

## 3. Criar o remote criptografado

Ainda como `root`:

```bash
rclone config
```

No wizard:

1. Escolha `n` para criar um novo remote.
2. Nome: `sierra_backup_crypt`.
3. Storage: `crypt`.
4. Remote to encrypt: `sierra_gdrive:SierraBackups`.
5. Encrypt filenames: `standard`.
6. Encrypt directory names: `true`.
7. Password: gere senha forte pelo proprio wizard.
8. Password2/salt: gere senha forte pelo proprio wizard.
9. Confirme o remote.

Proteja o arquivo de configuracao:

```bash
chmod 600 /root/.config/rclone/rclone.conf
```

Crie e teste a pasta de destino:

```bash
rclone mkdir sierra_backup_crypt:Sierra/production
echo ok >/tmp/sierra-rclone-test.txt
rclone copy /tmp/sierra-rclone-test.txt sierra_backup_crypt:Sierra/production/test/
rclone lsf sierra_backup_crypt:Sierra/production/test/
rclone deletefile sierra_backup_crypt:Sierra/production/test/sierra-rclone-test.txt
rclone rmdir sierra_backup_crypt:Sierra/production/test
rm -f /tmp/sierra-rclone-test.txt
```

Guarde a senha do `crypt` e o arquivo `/root/.config/rclone/rclone.conf` em cofre seguro. Sem eles, os backups enviados ao Drive nao podem ser restaurados.

## 4. Configurar variaveis do backup

Crie `/etc/sierra-backup.env`:

```bash
cat >/etc/sierra-backup.env <<'EOF'
BACKUP_ROOT=/home/docker/acadsoft/backups/sierra
RCLONE_REMOTE=sierra_backup_crypt:Sierra/production
MYSQL_CONTAINER=mysql_server
DB_NAME=sierra
RETENTION_DAYS=30
ESTOQUE_APP_DIR=/home/docker/acadsoft/sierra-estoque/html
AUTH_APP_DIR=/home/docker/acadsoft/sierra-auth/html
BACKUP_TIMEZONE=America/Sao_Paulo
EOF

chmod 600 /etc/sierra-backup.env
```

## 5. Configurar cron

Crie `/etc/cron.d/sierra-backup`:

```bash
cat >/etc/cron.d/sierra-backup <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""

# 05:30 UTC = 02:30 America/Sao_Paulo
30 5 * * * root flock -n /var/lock/sierra-backup.lock /home/docker/acadsoft/sierra-estoque/html/scripts/backup-production.sh >> /var/log/sierra-backup.log 2>&1
EOF

chmod 644 /etc/cron.d/sierra-backup
touch /var/log/sierra-backup.log
chmod 640 /var/log/sierra-backup.log
```

Crie `/etc/logrotate.d/sierra-backup`:

```bash
cat >/etc/logrotate.d/sierra-backup <<'EOF'
/var/log/sierra-backup.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    su root root
    create 0640 root root
}
EOF
```

## 6. Testar uma execucao

Valide a sintaxe:

```bash
bash -n /home/docker/acadsoft/sierra-estoque/html/scripts/backup-production.sh
```

Teste sem upload:

```bash
/home/docker/acadsoft/sierra-estoque/html/scripts/backup-production.sh --no-upload
```

Teste completo com upload:

```bash
/home/docker/acadsoft/sierra-estoque/html/scripts/backup-production.sh
```

Valide os artefatos do dia:

```bash
cd /home/docker/acadsoft/backups/sierra/$(TZ=America/Sao_Paulo date +%F)
gzip -t db-sierra-*.sql.gz
tar -tzf files-estoque-storage-*.tar.gz >/dev/null
tar -tzf files-auth-storage-*.tar.gz >/dev/null
tar -tzf secrets-sierra-*.tar.gz >/dev/null
sha256sum -c manifest-*.txt
rclone lsf sierra_backup_crypt:Sierra/production/$(TZ=America/Sao_Paulo date +%F)/
```

## 7. Restaurar banco em ambiente temporario

Exemplo para restaurar em banco temporario dentro do `mysql_server`:

```bash
BACKUP_DAY="$(TZ=America/Sao_Paulo date +%F)"
BACKUP_FILE="/home/docker/acadsoft/backups/sierra/$BACKUP_DAY/db-sierra-YYYYMMDD-HHMMSS.sql.gz"

docker exec mysql_server sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE IF NOT EXISTS sierra_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"'
gzip -dc "$BACKUP_FILE" | docker exec -i mysql_server sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot sierra_restore_test'
docker exec mysql_server sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -NBe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\''sierra_restore_test'\''"'
```

Depois do teste:

```bash
docker exec mysql_server sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS sierra_restore_test"'
```

## 8. Restaurar arquivos

Extraia em diretorio temporario antes de copiar para a aplicacao:

```bash
mkdir -p /tmp/sierra-restore-files
tar -xzf files-estoque-storage-YYYYMMDD-HHMMSS.tar.gz -C /tmp/sierra-restore-files
tar -xzf files-auth-storage-YYYYMMDD-HHMMSS.tar.gz -C /tmp/sierra-restore-files
tar -xzf secrets-sierra-YYYYMMDD-HHMMSS.tar.gz -C /tmp/sierra-restore-files
```

Confira o conteudo restaurado antes de sobrescrever qualquer pasta de producao.

## 9. Verificar o cron

Apos a primeira janela agendada:

```bash
grep sierra-backup /var/log/syslog
tail -200 /var/log/sierra-backup.log
```
