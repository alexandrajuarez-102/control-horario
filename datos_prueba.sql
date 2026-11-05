USE control_horario;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Empleados ficticios: direcciones y documentos de demostración, no datos reales.
-- Los hashes corresponden a contraseñas aleatorias descartadas; cambia las
-- contraseñas desde la aplicación si se habilita el acceso a estas cuentas.
INSERT IGNORE INTO usuarios (rol, nombre, dni, direccion, correo, password)
VALUES
    ('empleado', 'Lucía Martín Vega', 'DEMO-0001', 'Calle del Olmo 12, Madrid', 'empleado.demo01@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Hugo Fernández Ruiz', 'DEMO-0002', 'Avenida del Parque 8, Valencia', 'empleado.demo02@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Sofía López Campos', 'DEMO-0003', 'Calle Nueva 24, Sevilla', 'empleado.demo03@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Mateo Sánchez Gil', 'DEMO-0004', 'Plaza de la Estación 3, Zaragoza', 'empleado.demo04@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Valeria Torres Molina', 'DEMO-0005', 'Calle de la Fuente 17, Málaga', 'empleado.demo05@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Daniel Romero Pardo', 'DEMO-0006', 'Avenida Central 41, Bilbao', 'empleado.demo06@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Carla Navarro León', 'DEMO-0007', 'Calle del Río 6, Granada', 'empleado.demo07@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Pablo Jiménez Soto', 'DEMO-0008', 'Paseo de la Alameda 19, Alicante', 'empleado.demo08@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Nora Díaz Herrera', 'DEMO-0009', 'Calle Mayor 52, Valladolid', 'empleado.demo09@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy'),
    ('empleado', 'Adrián Castillo Ríos', 'DEMO-0010', 'Avenida del Mar 14, A Coruña', 'empleado.demo10@example.test', '$2y$10$jF.Ob2Xofb5om04N5NkWR.2cRrL1d1tPsN528FO34QSzoWHJTEOdy');

-- Corrige únicamente texto de las cuentas de prueba si una importación anterior
-- convirtió UTF-8 dos veces. El filtro HEX evita volver a modificar texto válido.
UPDATE usuarios
SET nombre = CONVERT(CAST(CONVERT(nombre USING latin1) AS BINARY) USING utf8mb4)
WHERE correo LIKE 'empleado.demo%@example.test'
  AND HEX(nombre) LIKE '%C383C2%';

UPDATE usuarios
SET direccion = CONVERT(CAST(CONVERT(direccion USING latin1) AS BINARY) USING utf8mb4)
WHERE correo LIKE 'empleado.demo%@example.test'
  AND direccion IS NOT NULL
  AND HEX(direccion) LIKE '%C383C2%';

UPDATE ausencias
JOIN usuarios ON usuarios.id = ausencias.usuario_id
SET ausencias.observaciones =
    CONVERT(CAST(CONVERT(ausencias.observaciones USING latin1) AS BINARY) USING utf8mb4)
WHERE usuarios.correo LIKE 'empleado.demo%@example.test'
  AND ausencias.observaciones IS NOT NULL
  AND HEX(ausencias.observaciones) LIKE '%C383C2%';

-- Asigna ciclos fijos y rotativos. El administrador existente queda intacto.
INSERT INTO asignaciones_turno
    (usuario_id, turno_id, fecha_inicio, fecha_fin, ciclo_inicio, asignado_por)
SELECT usuarios.id, plantillas_turnos.turno_id, CURDATE(), NULL, CURDATE(), administradores.id
FROM usuarios
CROSS JOIN (
    SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1
) AS administradores
JOIN (
    SELECT 'empleado.demo01@example.test' AS correo, 'Fijo 5x2' AS nombre_turno
    UNION ALL SELECT 'empleado.demo02@example.test', 'Rotativo 4x2'
    UNION ALL SELECT 'empleado.demo03@example.test', 'Rotativo 6x3'
    UNION ALL SELECT 'empleado.demo04@example.test', 'Fijo 5x2'
    UNION ALL SELECT 'empleado.demo05@example.test', 'Rotativo 4x2'
    UNION ALL SELECT 'empleado.demo06@example.test', 'Rotativo 6x3'
    UNION ALL SELECT 'empleado.demo07@example.test', 'Fijo 5x2'
    UNION ALL SELECT 'empleado.demo08@example.test', 'Rotativo 4x2'
    UNION ALL SELECT 'empleado.demo09@example.test', 'Rotativo 6x3'
    UNION ALL SELECT 'empleado.demo10@example.test', 'Fijo 5x2'
) AS demostracion ON demostracion.correo = usuarios.correo
JOIN plantillas_turnos ON plantillas_turnos.nombre_turno = demostracion.nombre_turno
WHERE usuarios.rol = 'empleado'
  AND NOT EXISTS (
      SELECT 1
      FROM asignaciones_turno existente
      WHERE existente.usuario_id = usuarios.id
        AND existente.fecha_inicio = CURDATE()
        AND existente.turno_id = plantillas_turnos.turno_id
  );

-- Vacaciones, permisos médicos genéricos, asuntos familiares, bajas y retrasos.
INSERT INTO ausencias
    (usuario_id, tipo, fecha_inicio, fecha_fin, observaciones, estado,
     creado_por, revisado_por, revisado_en_utc)
SELECT usuarios.id, demostracion.tipo, demostracion.fecha_inicio, demostracion.fecha_fin,
       demostracion.observaciones, demostracion.estado,
       CASE WHEN demostracion.tipo IN ('baja_medica', 'retraso', 'falta') THEN administradores.id ELSE usuarios.id END,
       CASE WHEN demostracion.estado IN ('aprobada', 'registrada') THEN administradores.id ELSE NULL END,
       CASE WHEN demostracion.estado IN ('aprobada', 'registrada') THEN UTC_TIMESTAMP(6) ELSE NULL END
FROM usuarios
CROSS JOIN (
    SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1
) AS administradores
JOIN (
    SELECT 'empleado.demo01@example.test' AS correo, 'vacaciones' AS tipo,
           DATE_ADD(CURDATE(), INTERVAL 14 DAY) AS fecha_inicio,
           DATE_ADD(CURDATE(), INTERVAL 18 DAY) AS fecha_fin,
           'Solicitud de vacaciones de demostración' AS observaciones, 'pendiente' AS estado
    UNION ALL SELECT 'empleado.demo02@example.test', 'medico',
           DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY),
           'Permiso para una cita médica', 'aprobada'
    UNION ALL SELECT 'empleado.demo03@example.test', 'retraso',
           DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY),
           'Retraso de demostración', 'registrada'
    UNION ALL SELECT 'empleado.demo04@example.test', 'vacaciones',
           DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 34 DAY),
           'Vacaciones de demostración', 'aprobada'
    UNION ALL SELECT 'empleado.demo05@example.test', 'medico',
           DATE_ADD(CURDATE(), INTERVAL 8 DAY), DATE_ADD(CURDATE(), INTERVAL 8 DAY),
           'Permiso para una cita médica', 'pendiente'
    UNION ALL SELECT 'empleado.demo06@example.test', 'asuntos_familiares',
           DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY),
           'Permiso por asuntos familiares', 'aprobada'
    UNION ALL SELECT 'empleado.demo07@example.test', 'baja_medica',
           DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY),
           'Registro de baja de demostración', 'registrada'
    UNION ALL SELECT 'empleado.demo08@example.test', 'retraso',
           CURDATE(), CURDATE(), 'Retraso de demostración', 'registrada'
    UNION ALL SELECT 'empleado.demo09@example.test', 'asuntos_familiares',
           DATE_ADD(CURDATE(), INTERVAL 6 DAY), DATE_ADD(CURDATE(), INTERVAL 6 DAY),
           'Solicitud por asuntos familiares', 'pendiente'
    UNION ALL SELECT 'empleado.demo09@example.test', 'falta',
           DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY),
           'Falta de demostración', 'registrada'
    UNION ALL SELECT 'empleado.demo10@example.test', 'vacaciones',
           DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 26 DAY),
           'Vacaciones de demostración', 'aprobada'
) AS demostracion ON demostracion.correo = usuarios.correo
WHERE usuarios.rol = 'empleado'
  AND NOT EXISTS (
      SELECT 1
      FROM ausencias existente
      WHERE existente.usuario_id = usuarios.id
        AND existente.tipo = demostracion.tipo
        AND existente.fecha_inicio = demostracion.fecha_inicio
        AND existente.fecha_fin = demostracion.fecha_fin
  );

-- Historial ficticio de los últimos 21 días naturales (solo días laborables).
-- Se omite hoy para que los empleados puedan registrar su jornada real.
-- No se mezclan jornadas si el empleado ya tiene algún fichaje en esa fecha.
INSERT INTO time_records
    (employee_code, usuario_id, event_type, recorded_at_utc, client_time_utc,
     time_zone, latitud, longitud, horas_trabajadas_segundos,
     horas_extra_segundos, es_hora_extra)
SELECT CONCAT('user:', empleado.id), empleado.id, eventos.event_type,
       TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '08:00:00')
           + INTERVAL (CASE eventos.event_type
               WHEN 'pausa' THEN 240
               WHEN 'fin_pausa' THEN
                   CASE WHEN MOD(empleado.id + dias.dia, 4) = 0 THEN 270 ELSE 300 END
               WHEN 'exit' THEN 540
               ELSE 0
             END) MINUTE,
       NULL, 'Europe/Madrid', NULL, NULL,
       CASE
           WHEN eventos.event_type <> 'exit' THEN NULL
           WHEN MOD(empleado.id + dias.dia, 4) = 0 THEN 30600
           ELSE 28800
       END,
       CASE
           WHEN eventos.event_type = 'exit'
            AND MOD(empleado.id + dias.dia, 4) = 0 THEN 1800
           WHEN eventos.event_type = 'exit' THEN 0
           ELSE NULL
       END,
       eventos.event_type = 'exit'
           AND MOD(empleado.id + dias.dia, 4) = 0
FROM (
    SELECT 1 AS dia UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
    SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL
    SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL
    SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL
    SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL
    SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL
    SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21
) AS dias
CROSS JOIN (
    SELECT 'entry' AS event_type
    UNION ALL SELECT 'pausa'
    UNION ALL SELECT 'fin_pausa'
    UNION ALL SELECT 'exit'
) AS eventos
JOIN usuarios AS empleado
  ON empleado.correo LIKE 'empleado.demo%@example.test'
 AND empleado.rol = 'empleado'
WHERE DAYOFWEEK(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY)) BETWEEN 2 AND 6
  AND NOT EXISTS (
      SELECT 1
      FROM time_records AS existente
      WHERE existente.usuario_id = empleado.id
        AND existente.recorded_at_utc >=
            TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '00:00:00')
        AND existente.recorded_at_utc <
            TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '00:00:00')
                + INTERVAL 1 DAY
  );
