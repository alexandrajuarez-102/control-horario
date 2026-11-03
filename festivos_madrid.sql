USE control_horario;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Festivos de Madrid para 2026, sin duplicar eventos ya existentes.
START TRANSACTION;

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Año Nuevo', '2026-01-01', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Año Nuevo' AND fecha = '2026-01-01' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Epifanía del Señor (Reyes)', '2026-01-06', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Epifanía del Señor (Reyes)' AND fecha = '2026-01-06' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Jueves Santo', '2026-04-02', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Jueves Santo' AND fecha = '2026-04-02' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Viernes Santo', '2026-04-03', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Viernes Santo' AND fecha = '2026-04-03' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Fiesta del Trabajo', '2026-05-01', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Fiesta del Trabajo' AND fecha = '2026-05-01' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Fiesta de la Comunidad de Madrid', '2026-05-02', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Fiesta de la Comunidad de Madrid' AND fecha = '2026-05-02' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'San Isidro', '2026-05-15', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'San Isidro' AND fecha = '2026-05-15' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Asunción de la Virgen', '2026-08-15', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Asunción de la Virgen' AND fecha = '2026-08-15' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Fiesta Nacional de España', '2026-10-12', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Fiesta Nacional de España' AND fecha = '2026-10-12' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Todos los Santos', '2026-11-01', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Todos los Santos' AND fecha = '2026-11-01' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Nuestra Señora de la Almudena', '2026-11-09', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Nuestra Señora de la Almudena' AND fecha = '2026-11-09' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Día de la Constitución Española', '2026-12-06', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Día de la Constitución Española' AND fecha = '2026-12-06' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Inmaculada Concepción', '2026-12-08', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Inmaculada Concepción' AND fecha = '2026-12-08' AND tipo = 'festivo'
);

INSERT INTO calendario_eventos (titulo, fecha, tipo)
SELECT 'Natividad del Señor', '2026-12-25', 'festivo'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM calendario_eventos
    WHERE titulo = 'Natividad del Señor' AND fecha = '2026-12-25' AND tipo = 'festivo'
);

COMMIT;
