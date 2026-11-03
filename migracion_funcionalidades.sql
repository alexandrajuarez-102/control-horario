USE control_horario;

CREATE TABLE IF NOT EXISTS plantillas_turnos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_turno VARCHAR(100) NOT NULL,
    tipo ENUM('fijo', 'rotativo') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    dias_trabajo TINYINT UNSIGNED NOT NULL,
    dias_descanso TINYINT UNSIGNED NOT NULL,
    turno_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plantillas_turnos_nombre (nombre_turno),
    UNIQUE KEY uq_plantillas_turnos_turno (turno_id),
    CONSTRAINT fk_plantillas_turnos_turno
        FOREIGN KEY (turno_id) REFERENCES turnos (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_plantillas_turnos_ciclo CHECK (dias_trabajo > 0 AND dias_descanso > 0)
) ENGINE=InnoDB;

SET @migration_sql = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'plantillas_turnos'
          AND column_name = 'horas_descanso'
    ) = 0,
    'ALTER TABLE plantillas_turnos ADD COLUMN horas_descanso DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER dias_descanso',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT INTO plantillas_turnos
    (nombre_turno, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso, turno_id)
SELECT turnos.nombre, turnos.tipo, turnos.hora_inicio, turnos.hora_fin,
       turnos.dias_trabajo, turnos.dias_descanso, turnos.id
FROM turnos
WHERE NOT EXISTS (
    SELECT 1 FROM plantillas_turnos WHERE plantillas_turnos.turno_id = turnos.id
);

CREATE TABLE IF NOT EXISTS calendario_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(160) NOT NULL,
    fecha DATE NOT NULL,
    tipo ENUM('evento', 'festivo') NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_calendario_eventos_fecha (fecha)
) ENGINE=InnoDB;

ALTER TABLE ausencias
    MODIFY tipo ENUM('vacaciones', 'medico', 'asuntos_familiares', 'baja_medica', 'retraso', 'falta') NOT NULL;
