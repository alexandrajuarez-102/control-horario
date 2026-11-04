<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();

if ($user === null) {
    http_response_code(401);
    exit('Inicia sesión para exportar los registros.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}

$employeeId = null;
if ($user['rol'] === 'empleado') {
    if (isset($_GET['usuario_id'])) {
        http_response_code(403);
        exit('Solo puedes exportar tus propios registros.');
    }
    $employeeId = $user['id'];
} elseif (isset($_GET['usuario_id']) && $_GET['usuario_id'] !== '') {
    $employeeId = filter_var($_GET['usuario_id'], FILTER_VALIDATE_INT);
    if ($employeeId === false || $employeeId < 1) {
        http_response_code(400);
        exit('El trabajador indicado no es válido.');
    }
}

$startDate = $_GET['desde'] ?? null;
$endDate = $_GET['hasta'] ?? null;
if (($startDate === null) !== ($endDate === null)) {
    http_response_code(400);
    exit('Debes indicar ambas fechas del periodo.');
}
if ($user['rol'] === 'empleado' && (!is_string($startDate) || $startDate !== $endDate)) {
    http_response_code(400);
    exit('Los empleados solo pueden descargar el detalle de un día cada vez.');
}

$madridTimeZone = new DateTimeZone('Europe/Madrid');
$utcTimeZone = new DateTimeZone('UTC');
$startUtc = null;
$endUtc = null;
if ($startDate !== null) {
    if (
        !is_string($startDate)
        || !is_string($endDate)
        || !isValidDate($startDate)
        || !isValidDate($endDate)
        || $endDate < $startDate
    ) {
        http_response_code(400);
        exit('El periodo de fechas no es válido.');
    }

    $startUtc = (new DateTimeImmutable($startDate, $madridTimeZone))
        ->setTime(0, 0)
        ->setTimezone($utcTimeZone)
        ->format('Y-m-d H:i:s.u');
    $endUtc = (new DateTimeImmutable($endDate, $madridTimeZone))
        ->modify('+1 day')
        ->setTime(0, 0)
        ->setTimezone($utcTimeZone)
        ->format('Y-m-d H:i:s.u');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    if ($employeeId !== null) {
        $employeeQuery = $connection->prepare(
            "SELECT nombre
             FROM usuarios
             WHERE id = :id AND rol = 'empleado'"
        );
        $employeeQuery->execute(['id' => $employeeId]);
        $employeeName = $employeeQuery->fetchColumn();
        if ($employeeName === false) {
            http_response_code(404);
            exit('No se encontró el trabajador indicado.');
        }
    }

    $query = 'SELECT
            time_records.event_type,
            time_records.recorded_at_utc,
            time_records.latitud,
            time_records.longitud,
            time_records.horas_trabajadas_segundos,
            time_records.horas_extra_segundos,
            time_records.es_hora_extra,
            time_records.es_cierre_automatico,
            usuarios.nombre,
            usuarios.dni
         FROM time_records
         LEFT JOIN usuarios ON usuarios.id = time_records.usuario_id
         WHERE 1 = 1';
    $parameters = [];
    if ($employeeId !== null) {
        $query .= ' AND time_records.usuario_id = :usuario_id';
        $parameters['usuario_id'] = $employeeId;
    }
    if ($startUtc !== null && $endUtc !== null) {
        $query .= ' AND time_records.recorded_at_utc >= :desde AND time_records.recorded_at_utc < :hasta';
        $parameters['desde'] = $startUtc;
        $parameters['hasta'] = $endUtc;
    }
    $query .= ' ORDER BY time_records.recorded_at_utc ASC, time_records.id ASC';
    $statement = $connection->prepare($query);
    $statement->execute($parameters);
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

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function protectSpreadsheetText(?string $value): string
{
    $value ??= '';

    if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

header('Content-Type: text/csv; charset=utf-8');
$filename = 'registros_horarios';
if (isset($employeeName)) {
    $filename .= '_trabajador_' . (int) $employeeId;
}
if ($startDate !== null && $endDate !== null) {
    $filename .= '_' . $startDate . '_' . $endDate;
} else {
    $filename .= '_' . gmdate('Y-m-d');
}
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
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
        (bool) $record['es_cierre_automatico']
            ? 'Salida (cierre automático)'
            : ($eventLabels[$record['event_type']] ?? $record['event_type']),
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
