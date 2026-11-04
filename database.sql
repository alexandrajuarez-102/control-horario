-- Esquema inicial compatible con MySQL y MariaDB (por ejemplo, XAMPP).
CREATE DATABASE IF NOT EXISTS control_horario
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE control_horario;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rol ENUM('admin', 'empleado') NOT NULL DEFAULT 'empleado',
    nombre VARCHAR(150) NOT NULL,
    cargo VARCHAR(100) NULL,
    dni VARCHAR(20) NULL,
    direccion VARCHAR(255) NULL,
    correo VARCHAR(254) NOT NULL,
    -- Guarda un hash generado con password_hash(), nunca la contraseña en texto plano.
    password VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_dni (dni),
    UNIQUE KEY uq_usuarios_correo (correo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS time_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Se conserva para compatibilidad con los registros de demostración existentes.
    employee_code VARCHAR(64) NOT NULL,
    usuario_id BIGINT UNSIGNED NULL,
    event_type ENUM('entry', 'exit', 'pausa', 'fin_pausa') NOT NULL,
    -- Instante oficial asignado por el servidor, almacenado en UTC.
    recorded_at_utc DATETIME(6) NOT NULL,
    -- Hora comunicada por el navegador; se conserva como dato auxiliar no confiable.
    client_time_utc DATETIME(6) NULL,
    time_zone VARCHAR(64) NOT NULL,
    -- Nullable para permitir conservar registros anteriores sin coordenadas.
    latitud DECIMAL(10, 7) NULL,
    longitud DECIMAL(10, 7) NULL,
    horas_trabajadas_segundos INT UNSIGNED NULL,
    horas_extra_segundos INT UNSIGNED NULL,
    es_hora_extra BOOLEAN NOT NULL DEFAULT FALSE,
    es_cierre_automatico BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (id),
    INDEX idx_employee_recorded_at (employee_code, recorded_at_utc),
    INDEX idx_usuario_recorded_at (usuario_id, recorded_at_utc),
    INDEX idx_recorded_at (recorded_at_utc),
    CONSTRAINT fk_time_records_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB;

-- También añade la relación al actualizar una base existente. Es seguro volver
-- a ejecutar este bloque: solo aplica los cambios que todavía no existan.
DROP PROCEDURE IF EXISTS migrate_time_records;
DELIMITER //
CREATE PROCEDURE migrate_time_records()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'usuario_id'
    ) THEN
        ALTER TABLE time_records
            ADD COLUMN usuario_id BIGINT UNSIGNED NULL AFTER employee_code,
            ADD INDEX idx_usuario_recorded_at (usuario_id, recorded_at_utc);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'latitud'
    ) THEN
        ALTER TABLE time_records ADD COLUMN latitud DECIMAL(10, 7) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'longitud'
    ) THEN
        ALTER TABLE time_records ADD COLUMN longitud DECIMAL(10, 7) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'horas_trabajadas_segundos'
    ) THEN
        ALTER TABLE time_records ADD COLUMN horas_trabajadas_segundos INT UNSIGNED NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'horas_extra_segundos'
    ) THEN
        ALTER TABLE time_records ADD COLUMN horas_extra_segundos INT UNSIGNED NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'es_hora_extra'
    ) THEN
        ALTER TABLE time_records ADD COLUMN es_hora_extra BOOLEAN NOT NULL DEFAULT FALSE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'es_cierre_automatico'
    ) THEN
        ALTER TABLE time_records ADD COLUMN es_cierre_automatico BOOLEAN NOT NULL DEFAULT FALSE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'time_records'
          AND column_name = 'event_type'
          AND LOCATE('pausa', column_type) > 0
          AND LOCATE('fin_pausa', column_type) > 0
    ) THEN
        ALTER TABLE time_records
            MODIFY COLUMN event_type ENUM('entry', 'exit', 'pausa', 'fin_pausa') NOT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'time_records'
          AND constraint_name = 'fk_time_records_usuario'
    ) THEN
        ALTER TABLE time_records
            ADD CONSTRAINT fk_time_records_usuario
            FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;

CALL migrate_time_records();
DROP PROCEDURE migrate_time_records;

CREATE TABLE IF NOT EXISTS turnos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM('fijo', 'rotativo') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    dias_trabajo TINYINT UNSIGNED NOT NULL,
    dias_descanso TINYINT UNSIGNED NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (id),
    UNIQUE KEY uq_turnos_nombre (nombre),
    CONSTRAINT chk_turnos_ciclo CHECK (dias_trabajo > 0 AND dias_descanso > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plantillas_turnos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_turno VARCHAR(100) NOT NULL,
    tipo ENUM('fijo', 'rotativo') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    dias_trabajo TINYINT UNSIGNED NOT NULL,
    dias_descanso TINYINT UNSIGNED NOT NULL,
    horas_descanso DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    turno_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plantillas_turnos_nombre (nombre_turno),
    UNIQUE KEY uq_plantillas_turnos_turno (turno_id),
    CONSTRAINT fk_plantillas_turnos_turno
        FOREIGN KEY (turno_id) REFERENCES turnos (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_plantillas_turnos_ciclo CHECK (dias_trabajo > 0 AND dias_descanso > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asignaciones_turno (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    turno_id BIGINT UNSIGNED NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NULL,
    ciclo_inicio DATE NOT NULL,
    asignado_por BIGINT UNSIGNED NOT NULL,
    creado_en_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    INDEX idx_asignaciones_usuario_fechas (usuario_id, fecha_inicio, fecha_fin),
    CONSTRAINT fk_asignaciones_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_asignaciones_turno
        FOREIGN KEY (turno_id) REFERENCES turnos (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_asignaciones_admin
        FOREIGN KEY (asignado_por) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_asignaciones_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS calendario_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(160) NOT NULL,
    fecha DATE NOT NULL,
    tipo ENUM('evento', 'festivo') NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_calendario_eventos_fecha (fecha)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ausencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM(
        'vacaciones', 'medico', 'asuntos_familiares', 'fallecimiento_familiar',
        'hospitalizacion_familiar', 'deber_inexcusable', 'mudanza', 'matrimonio',
        'nacimiento_cuidado', 'lactancia', 'fuerza_mayor_familiar',
        'permiso_retribuido', 'permiso_no_retribuido', 'otros',
        'baja_medica', 'retraso', 'falta'
    ) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    observaciones VARCHAR(1000) NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada', 'registrada') NOT NULL DEFAULT 'pendiente',
    creado_por BIGINT UNSIGNED NOT NULL,
    revisado_por BIGINT UNSIGNED NULL,
    creado_en_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revisado_en_utc DATETIME(6) NULL,
    PRIMARY KEY (id),
    INDEX idx_ausencias_usuario_fechas (usuario_id, fecha_inicio, fecha_fin),
    INDEX idx_ausencias_estado_fecha (estado, fecha_inicio),
    CONSTRAINT fk_ausencias_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_ausencias_creado_por
        FOREIGN KEY (creado_por) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_ausencias_revisado_por
        FOREIGN KEY (revisado_por) REFERENCES usuarios (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_ausencias_fechas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB;

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

INSERT INTO turnos (nombre, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso)
VALUES
    ('Fijo 5x2', 'fijo', '09:00:00', '17:00:00', 5, 2),
    ('Rotativo 4x2', 'rotativo', '08:00:00', '16:00:00', 4, 2),
    ('Rotativo 6x3', 'rotativo', '08:00:00', '16:00:00', 6, 3)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plantillas_turnos
    (nombre_turno, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso, turno_id)
SELECT turnos.nombre, turnos.tipo, turnos.hora_inicio, turnos.hora_fin,
       turnos.dias_trabajo, turnos.dias_descanso, turnos.id
FROM turnos
WHERE NOT EXISTS (
    SELECT 1 FROM plantillas_turnos WHERE plantillas_turnos.turno_id = turnos.id
);

-- No se configura borrado automático: los registros deben conservarse al menos
-- durante cuatro años. Define una política de retención y borrado autorizado.
