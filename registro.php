<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['message' => 'Método no permitido.']);
}

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();
if ($user === null) {
    respond(401, ['message' => 'Inicia sesión para registrar un fichaje.']);
}
if ($user['rol'] !== 'empleado') {
    respond(403, ['message' => 'Solo los empleados pueden registrar fichajes.']);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrfToken) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(403, ['message' => 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.']);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    respond(400, ['message' => 'El cuerpo debe ser un objeto JSON válido.']);
}

$eventType = $payload['eventType'] ?? null;
$clientTimestamp = $payload['clientTimestamp'] ?? null;
$timeZone = $payload['timeZone'] ?? null;

if (!in_array($eventType, ['entry', 'exit', 'pausa', 'fin_pausa'], true)) {
    respond(400, ['message' => 'El tipo de fichaje no es válido.']);
}

$latitude = $payload['latitud'] ?? null;
$longitude = $payload['longitud'] ?? null;
if (
    (!is_int($latitude) && !is_float($latitude))
    || (!is_int($longitude) && !is_float($longitude))
    || !is_finite((float) $latitude)
    || !is_finite((float) $longitude)
    || $latitude < -90
    || $latitude > 90
    || $longitude < -180
    || $longitude > 180
) {
    respond(400, ['message' => 'Se requieren coordenadas GPS válidas para registrar el fichaje.']);
}

if (
    !is_string($clientTimestamp)
    || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $clientTimestamp)
) {
    respond(400, ['message' => 'La hora comunicada por el navegador no es válida.']);
}

if (!is_string($timeZone) || strlen($timeZone) > 64) {
    respond(400, ['message' => 'La zona horaria no es válida.']);
}

try {
    $clientTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s.v\Z',
        $clientTimestamp,
        new DateTimeZone('UTC')
    );
    $timestampErrors = DateTimeImmutable::getLastErrors();
    if (
        $clientTime === false
        || ($timestampErrors !== false && ($timestampErrors['warning_count'] > 0 || $timestampErrors['error_count'] > 0))
    ) {
        respond(400, ['message' => 'La hora comunicada por el navegador no es válida.']);
    }
    new DateTimeZone($timeZone);
} catch (Exception $exception) {
    respond(400, ['message' => 'La hora o la zona horaria no son válidas.']);
}

require_once __DIR__ . '/conexion.php';

try {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $connection = getDatabaseConnection();
    $connection->beginTransaction();

    $userLock = $connection->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
    $userLock->execute(['id' => $user['id']]);
    if ($userLock->fetchColumn() === false) {
        $connection->rollBack();
        respond(401, ['message' => 'La cuenta ya no está disponible. Inicia sesión de nuevo.']);
    }

    $localDate = $now->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d');
    $dayStart = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $localDate,
        new DateTimeZone('Europe/Madrid')
    );
    if ($dayStart === false) {
        $connection->rollBack();
        respond(500, ['message' => 'No se pudo determinar la fecha del fichaje.']);
    }
    $dayStartUtc = $dayStart->setTimezone(new DateTimeZone('UTC'));
    $dayEndUtc = $dayStart->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));

    $recordsQuery = $connection->prepare(
        'SELECT event_type, recorded_at_utc
         FROM time_records
         WHERE usuario_id = :usuario_id
           AND recorded_at_utc >= :day_start
           AND recorded_at_utc < :day_end
         ORDER BY recorded_at_utc ASC, id ASC
         FOR UPDATE'
    );
    $recordsQuery->execute([
        'usuario_id' => $user['id'],
        'day_start' => $dayStartUtc->format('Y-m-d H:i:s.u'),
        'day_end' => $dayEndUtc->format('Y-m-d H:i:s.u'),
    ]);

    $state = 'off';
    $workStartedAt = null;
    $workedSeconds = 0.0;
    $invalidSequence = false;
    foreach ($recordsQuery->fetchAll() as $record) {
        $recordedAt = new DateTimeImmutable($record['recorded_at_utc'], new DateTimeZone('UTC'));
        switch ($record['event_type']) {
            case 'entry':
                if ($state !== 'off') {
                    $invalidSequence = true;
                    break 2;
                }
                $state = 'working';
                $workStartedAt = $recordedAt;
                break;
            case 'pausa':
                if ($state !== 'working' || $workStartedAt === null) {
                    $invalidSequence = true;
                    break 2;
                }
                $workedSeconds += (float) $recordedAt->format('U.u') - (float) $workStartedAt->format('U.u');
                $state = 'paused';
                $workStartedAt = null;
                break;
            case 'fin_pausa':
                if ($state !== 'paused') {
                    $invalidSequence = true;
                    break 2;
                }
                $state = 'working';
                $workStartedAt = $recordedAt;
                break;
            case 'exit':
                if ($state !== 'working' || $workStartedAt === null) {
                    $invalidSequence = true;
                    break 2;
                }
                $workedSeconds += (float) $recordedAt->format('U.u') - (float) $workStartedAt->format('U.u');
                $state = 'off';
                $workStartedAt = null;
                break;
        }
    }

    if ($invalidSequence) {
        $connection->rollBack();
        respond(409, ['message' => 'La secuencia de fichajes de hoy no es válida. Contacta con administración para revisarla.']);
    }

    $validNextEvents = [
        'off' => ['entry'],
        'working' => ['pausa', 'exit'],
        'paused' => ['fin_pausa'],
    ];
    if (!in_array($eventType, $validNextEvents[$state], true)) {
        $connection->rollBack();
        respond(409, ['message' => 'Este fichaje no corresponde al estado actual de tu jornada.']);
    }

    $recordWorkedSeconds = null;
    $overtimeSeconds = null;
    $isOvertime = false;
    if ($eventType === 'exit' && $workStartedAt !== null) {
        $workedSeconds += (float) $now->format('U.u') - (float) $workStartedAt->format('U.u');
        $recordWorkedSeconds = max(0, (int) floor($workedSeconds));
        $overtimeSeconds = max(0, $recordWorkedSeconds - 8 * 60 * 60);
        $isOvertime = $overtimeSeconds > 0;
    }

    $statement = $connection->prepare(
        'INSERT INTO time_records
            (employee_code, usuario_id, event_type, recorded_at_utc, client_time_utc, time_zone,
             latitud, longitud, horas_trabajadas_segundos, horas_extra_segundos, es_hora_extra)
         VALUES
            (:employee_code, :usuario_id, :event_type, :recorded_at_utc, :client_time_utc, :time_zone,
             :latitud, :longitud, :horas_trabajadas_segundos, :horas_extra_segundos, :es_hora_extra)'
    );
    $statement->execute([
        'employee_code' => 'user:' . $user['id'],
        'usuario_id' => $user['id'],
        'event_type' => $eventType,
        'recorded_at_utc' => $now->format('Y-m-d H:i:s.u'),
        'client_time_utc' => $clientTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        'time_zone' => $timeZone,
        'latitud' => number_format((float) $latitude, 7, '.', ''),
        'longitud' => number_format((float) $longitude, 7, '.', ''),
        'horas_trabajadas_segundos' => $recordWorkedSeconds,
        'horas_extra_segundos' => $overtimeSeconds,
        'es_hora_extra' => $isOvertime ? 1 : 0,
    ]);
    $recordId = (int) $connection->lastInsertId();
    $connection->commit();

    respond(201, [
        'record' => [
            'id' => $recordId,
            'eventType' => $eventType,
            'recordedAtUtc' => $now->format('Y-m-d\TH:i:s.u\Z'),
            'workedSeconds' => $recordWorkedSeconds,
            'overtimeSeconds' => $overtimeSeconds,
            'isOvertime' => $isOvertime,
        ],
    ]);
} catch (PDOException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('No se pudo guardar un fichaje: ' . $exception->getMessage());
    respond(500, ['message' => 'No se pudo guardar el fichaje. Inténtalo de nuevo más tarde.']);
}
