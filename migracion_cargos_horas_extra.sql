USE control_horario;

SET @migration_sql = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'usuarios'
          AND column_name = 'cargo'
    ) = 0,
    'ALTER TABLE usuarios ADD COLUMN cargo VARCHAR(100) NULL AFTER nombre',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS solicitudes_horas_extra (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    minutos SMALLINT UNSIGNED NOT NULL,
    motivo VARCHAR(1000) NOT NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    revisado_por BIGINT UNSIGNED NULL,
    creado_en_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revisado_en_utc DATETIME(6) NULL,
    PRIMARY KEY (id),
    INDEX idx_solicitudes_horas_extra_estado_fecha (estado, fecha),
    INDEX idx_solicitudes_horas_extra_usuario_fecha (usuario_id, fecha),
    CONSTRAINT fk_solicitudes_horas_extra_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_solicitudes_horas_extra_revisor
        FOREIGN KEY (revisado_por) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_solicitudes_horas_extra_minutos CHECK (minutos BETWEEN 1 AND 720)
) ENGINE=InnoDB;
