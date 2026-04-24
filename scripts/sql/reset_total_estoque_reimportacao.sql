-- Reset total do schema `estoque` para reimportacao inicial.
-- Preserva autenticacao, configuracoes e historico de migrations.
-- Execute em uma sessao MySQL conectada ao banco `estoque`.

USE `estoque`;

SELECT DATABASE() AS schema_alvo;

SELECT COUNT(*) AS total_tabelas_preservadas
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE'
  AND table_name IN (
    'migrations',
    'configuracoes',
    'acesso_usuarios',
    'acesso_perfis',
    'acesso_permissoes',
    'acesso_usuario_perfil',
    'acesso_perfil_permissao',
    'personal_access_tokens',
    'acesso_refresh_tokens'
  );

SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE'
  AND table_name NOT IN (
    'migrations',
    'configuracoes',
    'acesso_usuarios',
    'acesso_perfis',
    'acesso_permissoes',
    'acesso_usuario_perfil',
    'acesso_perfil_permissao',
    'personal_access_tokens',
    'acesso_refresh_tokens'
  )
ORDER BY table_name;

DROP PROCEDURE IF EXISTS sp_reset_total_estoque_reimportacao;

DELIMITER $$

CREATE PROCEDURE sp_reset_total_estoque_reimportacao()
BEGIN
    DECLARE done TINYINT DEFAULT 0;
    DECLARE v_table_name VARCHAR(64);

    DECLARE cur CURSOR FOR
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_type = 'BASE TABLE'
          AND table_name NOT IN (
            'migrations',
            'configuracoes',
            'acesso_usuarios',
            'acesso_perfis',
            'acesso_permissoes',
            'acesso_usuario_perfil',
            'acesso_perfil_permissao',
            'personal_access_tokens',
            'acesso_refresh_tokens'
          )
        ORDER BY table_name;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET FOREIGN_KEY_CHECKS = 1;
        RESIGNAL;
    END;

    SET FOREIGN_KEY_CHECKS = 0;

    OPEN cur;

    truncate_loop: LOOP
        FETCH cur INTO v_table_name;

        IF done = 1 THEN
            LEAVE truncate_loop;
        END IF;

        SET @truncate_sql = CONCAT(
            'TRUNCATE TABLE `',
            DATABASE(),
            '`.`',
            REPLACE(v_table_name, '`', '``'),
            '`'
        );

        SELECT @truncate_sql AS executando;

        PREPARE truncate_stmt FROM @truncate_sql;
        EXECUTE truncate_stmt;
        DEALLOCATE PREPARE truncate_stmt;
    END LOOP;

    CLOSE cur;

    SET FOREIGN_KEY_CHECKS = 1;
END $$

DELIMITER ;

CALL sp_reset_total_estoque_reimportacao();
DROP PROCEDURE IF EXISTS sp_reset_total_estoque_reimportacao;
