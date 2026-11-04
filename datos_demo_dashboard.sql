USE control_horario;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Datos sintéticos para las gráficas del dashboard.
-- Requiere las cuentas empleado.demo01..demo10@example.test de datos_prueba.sql.
START TRANSACTION;

-- No dejes fichajes cerrados de demostración en el día activo: impedirían
-- que los empleados de prueba registren su jornada real.
DELETE time_records
FROM time_records
JOIN usuarios ON usuarios.id = time_records.usuario_id
WHERE usuarios.correo IN (
        'empleado.demo05@example.test',
        'empleado.demo06@example.test',
        'empleado.demo07@example.test',
        'empleado.demo08@example.test',
        'empleado.demo09@example.test',
        'empleado.demo10@example.test'
    )
  AND time_records.recorded_at_utc >= UTC_DATE()
  AND time_records.recorded_at_utc < DATE_ADD(UTC_DATE(), INTERVAL 1 DAY)
  AND time_records.client_time_utc IS NULL
  AND time_records.latitud IS NULL
  AND time_records.longitud IS NULL
  AND (
      (time_records.event_type = 'entry' AND TIME_FORMAT(time_records.recorded_at_utc, '%H:%i:%s') = '08:00:00')
      OR (time_records.event_type = 'exit' AND TIME_FORMAT(time_records.recorded_at_utc, '%H:%i:%s') = '17:00:00')
  );

-- Dos ausencias aprobadas cubren hoy y se muestran como baja/vacaciones.
INSERT INTO ausencias
    (usuario_id, tipo, fecha_inicio, fecha_fin, observaciones, estado,
     creado_por, revisado_por, revisado_en_utc)
SELECT empleado.id, demostracion.tipo,
       DATE_SUB(CURDATE(), INTERVAL 2 DAY),
       DATE_ADD(CURDATE(), INTERVAL 3 DAY),
       demostracion.observaciones, 'aprobada',
       administrador.id, administrador.id, UTC_TIMESTAMP(6)
FROM (
    SELECT 'empleado.demo01@example.test' AS correo,
           'baja_medica' AS tipo,
           'Demo dashboard: baja médica' AS observaciones
    UNION ALL
    SELECT 'empleado.demo02@example.test', 'vacaciones',
           'Demo dashboard: vacaciones'
) AS demostracion
JOIN usuarios AS empleado
  ON empleado.correo = demostracion.correo
 AND empleado.rol = 'empleado'
CROSS JOIN (
    SELECT id
    FROM usuarios
    WHERE rol = 'admin'
    ORDER BY id
    LIMIT 1
) AS administrador
WHERE NOT EXISTS (
    SELECT 1
    FROM ausencias AS existente
    WHERE existente.usuario_id = empleado.id
      AND existente.observaciones = demostracion.observaciones
);

-- Siete días de jornadas cerradas: empleados demo05..demo10 alternan
-- jornadas de nueve y diez horas, con las horas extra ya calculadas.
INSERT INTO time_records
    (employee_code, usuario_id, event_type, recorded_at_utc, client_time_utc,
     time_zone, latitud, longitud, horas_trabajadas_segundos,
     horas_extra_segundos, es_hora_extra)
SELECT CONCAT('user:', empleado.id), empleado.id, 'entry',
       TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '08:00:00'),
       NULL, 'Europe/Madrid', NULL, NULL, NULL, NULL, FALSE
FROM (
    SELECT 1 AS dia UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
    SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) AS dias
CROSS JOIN (
    SELECT 'empleado.demo05@example.test' AS correo UNION ALL
    SELECT 'empleado.demo06@example.test' UNION ALL
    SELECT 'empleado.demo07@example.test' UNION ALL
    SELECT 'empleado.demo08@example.test' UNION ALL
    SELECT 'empleado.demo09@example.test' UNION ALL
    SELECT 'empleado.demo10@example.test'
) AS demostracion
JOIN usuarios AS empleado
  ON empleado.correo = demostracion.correo
 AND empleado.rol = 'empleado'
WHERE NOT EXISTS (
    SELECT 1
    FROM time_records AS existente
    WHERE existente.usuario_id = empleado.id
      AND existente.event_type = 'entry'
      AND existente.recorded_at_utc =
          TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '08:00:00')
);

INSERT INTO time_records
    (employee_code, usuario_id, event_type, recorded_at_utc, client_time_utc,
     time_zone, latitud, longitud, horas_trabajadas_segundos,
     horas_extra_segundos, es_hora_extra)
SELECT CONCAT('user:', empleado.id), empleado.id, 'exit',
       TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '17:00:00'),
       NULL, 'Europe/Madrid', NULL, NULL,
       CASE WHEN MOD(empleado.id, 2) = 0 THEN 36000 ELSE 32400 END,
       CASE WHEN MOD(empleado.id, 2) = 0 THEN 7200 ELSE 3600 END,
       TRUE
FROM (
    SELECT 1 AS dia UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
    SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) AS dias
CROSS JOIN (
    SELECT 'empleado.demo05@example.test' AS correo UNION ALL
    SELECT 'empleado.demo06@example.test' UNION ALL
    SELECT 'empleado.demo07@example.test' UNION ALL
    SELECT 'empleado.demo08@example.test' UNION ALL
    SELECT 'empleado.demo09@example.test' UNION ALL
    SELECT 'empleado.demo10@example.test'
) AS demostracion
JOIN usuarios AS empleado
  ON empleado.correo = demostracion.correo
 AND empleado.rol = 'empleado'
WHERE NOT EXISTS (
    SELECT 1
    FROM time_records AS existente
    WHERE existente.usuario_id = empleado.id
      AND existente.event_type = 'exit'
      AND existente.recorded_at_utc =
          TIMESTAMP(DATE_SUB(UTC_DATE(), INTERVAL dias.dia DAY), '17:00:00')
);

-- Dos entradas abiertas recientes; sus empleados no tienen otra entrada hoy.
INSERT INTO time_records
    (employee_code, usuario_id, event_type, recorded_at_utc, client_time_utc,
     time_zone, latitud, longitud, horas_trabajadas_segundos,
     horas_extra_segundos, es_hora_extra)
SELECT CONCAT('user:', empleado.id), empleado.id, 'entry',
       UTC_TIMESTAMP(6) - INTERVAL 2 HOUR,
       NULL, 'Europe/Madrid', NULL, NULL, NULL, NULL, FALSE
FROM (
    SELECT 'empleado.demo03@example.test' AS correo UNION ALL
    SELECT 'empleado.demo04@example.test'
) AS demostracion
JOIN usuarios AS empleado
  ON empleado.correo = demostracion.correo
 AND empleado.rol = 'empleado'
WHERE NOT EXISTS (
    SELECT 1
    FROM time_records AS existente
    WHERE existente.usuario_id = empleado.id
      AND existente.event_type = 'entry'
      AND existente.recorded_at_utc >= UTC_DATE()
      AND existente.recorded_at_utc < DATE_ADD(UTC_DATE(), INTERVAL 1 DAY)
);

COMMIT;
