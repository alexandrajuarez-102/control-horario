USE control_horario;

SET @migration_sql = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'es_cierre_automatico'
    ) = 0,
    'ALTER TABLE time_records ADD COLUMN es_cierre_automatico BOOLEAN NOT NULL DEFAULT FALSE AFTER es_hora_extra',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
