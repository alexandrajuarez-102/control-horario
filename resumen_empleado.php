<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respondWithSummary(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respondWithSummary(405, ['message' => 'Método no permitido.']);
}

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();
if ($user === null) {
    respondWithSummary(401, ['message' => 'Inicia sesión para consultar tu resumen.']);
}
if ($user['rol'] !== 'empleado') {
    respondWithSummary(403, ['message' => 'Esta consulta está disponible para empleados.']);
}

$monthValue = $_GET['mes'] ?? null;
if (!is_string($monthValue) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthValue) !== 1) {
    respondWithSummary(400, ['message' => 'El mes indicado no es válido.']);
}

$madrid = new DateTimeZone('Europe/Madrid');
$utc = new DateTimeZone('UTC');
$monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $monthValue . '-01', $madrid);
$dateErrors = DateTimeImmutable::getLastErrors();
if (
    $monthStart === false
    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    || $monthStart->format('Y-m') !== $monthValue
) {
    respondWithSummary(400, ['message' => 'El mes indicado no es válido.']);
}

$monthEnd = $monthStart->modify('+1 month');
$queryStart = $monthStart->modify('-2 days')->setTimezone($utc);
$queryEnd = $monthEnd->modify('+2 days')->setTimezone($utc);

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $statement = $connection->prepare(
        'SELECT event_type, recorded_at_utc, horas_trabajadas_segundos, horas_extra_segundos
         FROM time_records
         WHERE usuario_id = :usuario_id
           AND recorded_at_utc >= :start_utc
           AND recorded_at_utc < :end_utc
         ORDER BY recorded_at_utc ASC, id ASC'
    );
    $statement->execute([
        'usuario_id' => $user['id'],
        'start_utc' => $queryStart->format('Y-m-d H:i:s.u'),
        'end_utc' => $queryEnd->format('Y-m-d H:i:s.u'),
    ]);

    $days = [];
    $workDate = null;
    $state = 'off';
    foreach ($statement->fetchAll() as $record) {
        $recordedAt = (new DateTimeImmutable($record['recorded_at_utc'], $utc))->setTimezone($madrid);
        if ($record['event_type'] === 'entry' && $state === 'off') {
            $workDate = $recordedAt->format('Y-m-d');
            $state = 'working';
            continue;
        }
        if ($record['event_type'] === 'pausa' && $state === 'working') {
            $state = 'paused';
            continue;
        }
        if ($record['event_type'] === 'fin_pausa' && $state === 'paused') {
            $state = 'working';
            continue;
        }
        if ($record['event_type'] !== 'exit' || !in_array($state, ['working', 'paused'], true)) {
            continue;
        }

        $summaryDate = $workDate ?? $recordedAt->format('Y-m-d');
        if (
            $summaryDate >= $monthStart->format('Y-m-d')
            && $summaryDate < $monthEnd->format('Y-m-d')
            && $record['horas_trabajadas_segundos'] !== null
        ) {
            $days[$summaryDate] ??= ['worked' => 0, 'overtime' => 0];
            $days[$summaryDate]['worked'] += (int) $record['horas_trabajadas_segundos'];
            $days[$summaryDate]['overtime'] += (int) ($record['horas_extra_segundos'] ?? 0);
        }
        $workDate = null;
        $state = 'off';
    }

    $weeks = [];
    $monthWorked = 0;
    $monthOvertime = 0;
    foreach ($days as $date => $totals) {
        $dateObject = new DateTimeImmutable($date, $madrid);
        $weekStart = $dateObject->modify('monday this week');
        $weekKey = $weekStart->format('Y-m-d');
        $weeks[$weekKey] ??= ['weekStart' => $weekKey, 'worked' => 0, 'days' => 0];
        $weeks[$weekKey]['worked'] += $totals['worked'];
        $weeks[$weekKey]['days']++;
        $monthWorked += $totals['worked'];
        $monthOvertime += $totals['overtime'];
    }

    ksort($weeks);
    respondWithSummary(200, [
        'month' => $monthValue,
        'workedSeconds' => $monthWorked,
        'overtimeSeconds' => $monthOvertime,
        'workedDays' => count($days),
        'weeks' => array_values($weeks),
    ]);
} catch (PDOException $exception) {
    error_log('No se pudo generar el resumen mensual del empleado: ' . $exception->getMessage());
    respondWithSummary(500, ['message' => 'No se pudo cargar tu resumen. Inténtalo más tarde.']);
}
