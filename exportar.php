<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();

if ($user === null) {
    http_response_code(401);
    exit('Inicia sesión para exportar los registros.');
}

if ($user['rol'] !== 'admin') {
    http_response_code(403);
    exit('No tienes permisos para exportar los registros.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $statement = $connection->query(
        'SELECT
            time_records.event_type,
            time_records.recorded_at_utc,
            time_records.latitud,
            time_records.longitud,
            time_records.horas_trabajadas_segundos,
            time_records.horas_extra_segundos,
            time_records.es_hora_extra,
            usuarios.nombre,
            usuarios.dni
         FROM time_records
         LEFT JOIN usuarios ON usuarios.id = time_records.usuario_id
         ORDER BY time_records.recorded_at_utc ASC, time_records.id ASC'
    );
} catch (PDOException $exception) {
    error_log('No se pudo generar la exportación de fichajes: ' . $exception->getMessage());
    http_response_code(500);
    exit('No se pudo generar el archivo. Inténtalo más tarde.');
}

$eventLabels = [
    'entry' => 'Entrada',
    'exit' => 'Salida',
    'pausa' => 'Inicio de pausa',
    'fin_pausa' => 'Fin de pausa',
];
$madridTimeZone = new DateTimeZone('Europe/Madrid');

function protectSpreadsheetText(?string $value): string
{
    $value ??= '';

    if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="registros_horarios_' . gmdate('Y-m-d') . '.csv"');
header('Cache-Control: no-store, private');

$output = fopen('php://output', 'wb');
if ($output === false) {
    error_log('No se pudo abrir la salida CSV.');
    http_response_code(500);
    exit('No se pudo generar el archivo.');
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv(
    $output,
    ['Nombre', 'DNI', 'Fecha', 'Hora', 'Tipo de fichaje', 'Latitud', 'Longitud', 'Horas trabajadas', 'Horas extra'],
    ';',
    '"',
    '',
    "\r\n"
);

while ($record = $statement->fetch()) {
    $recordedAt = new DateTimeImmutable($record['recorded_at_utc'], new DateTimeZone('UTC'));
    $recordedAt = $recordedAt->setTimezone($madridTimeZone);

    fputcsv($output, [
        protectSpreadsheetText($record['nombre'] ?? 'Sin usuario asociado'),
        protectSpreadsheetText($record['dni']),
        $recordedAt->format('d/m/Y'),
        $recordedAt->format('H:i:s.u'),
        $eventLabels[$record['event_type']] ?? $record['event_type'],
        $record['latitud'] ?? '',
        $record['longitud'] ?? '',
        $record['horas_trabajadas_segundos'] === null
            ? ''
            : gmdate('H:i:s', (int) $record['horas_trabajadas_segundos']),
        $record['horas_extra_segundos'] === null
            ? ''
            : gmdate('H:i:s', (int) $record['horas_extra_segundos']),
    ], ';', '"', '', "\r\n");
}

fclose($output);
