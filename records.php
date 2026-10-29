<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, ['message' => 'Método no permitido.']);
}

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();
if ($user === null) {
    respond(401, ['message' => 'Inicia sesión para consultar tus registros.']);
}
if ($user['rol'] !== 'empleado') {
    respond(403, ['message' => 'Esta consulta está disponible para empleados.']);
}

$dateValue = $_GET['date'] ?? null;
if (!is_string($dateValue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
    respond(400, ['message' => 'La fecha no es válida.']);
}

$madridTimeZone = new DateTimeZone('Europe/Madrid');
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $madridTimeZone);
$dateErrors = DateTimeImmutable::getLastErrors();
if (
    $start === false
    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    || $start->format('Y-m-d') !== $dateValue
) {
    respond(400, ['message' => 'La fecha no es válida.']);
}
$end = $start->modify('+1 day');

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $statement = $connection->prepare(
        'SELECT id, event_type, recorded_at_utc,
                horas_trabajadas_segundos, horas_extra_segundos, es_hora_extra
         FROM time_records
         WHERE usuario_id = :usuario_id
           AND recorded_at_utc >= :start
           AND recorded_at_utc < :end
         ORDER BY recorded_at_utc ASC, id ASC'
    );
    $statement->execute([
        'usuario_id' => $user['id'],
        'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        'end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
    ]);

    $records = array_map(
        static fn (array $record): array => [
            'id' => (int) $record['id'],
            'eventType' => $record['event_type'],
            'recordedAtUtc' => str_replace(' ', 'T', $record['recorded_at_utc']) . 'Z',
            'workedSeconds' => $record['horas_trabajadas_segundos'] === null
                ? null
                : (int) $record['horas_trabajadas_segundos'],
            'overtimeSeconds' => $record['horas_extra_segundos'] === null
                ? null
                : (int) $record['horas_extra_segundos'],
            'isOvertime' => (bool) $record['es_hora_extra'],
        ],
        $statement->fetchAll()
    );

    respond(200, ['records' => $records]);
} catch (PDOException $exception) {
    error_log('No se pudieron consultar los fichajes: ' . $exception->getMessage());
    respond(500, ['message' => 'No se pudieron cargar los registros. Inténtalo de nuevo más tarde.']);
}
