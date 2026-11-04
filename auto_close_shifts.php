<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/horario_empleado.php';

function closeExpiredShifts(PDO $connection, DateTimeImmutable $now): int
{
    static $reportedInvalidSequences = [];
    $employeeQuery = $connection->query("SELECT id FROM usuarios WHERE rol = 'empleado' ORDER BY id");
    $closedCount = 0;
    $madrid = new DateTimeZone('Europe/Madrid');
    $utc = new DateTimeZone('UTC');
    $today = $now->setTimezone($madrid)->setTime(0, 0);

    foreach ($employeeQuery->fetchAll(PDO::FETCH_COLUMN) as $employeeIdValue) {
        $employeeId = (int) $employeeIdValue;
        foreach ([$today->modify('-1 day'), $today] as $workDate) {
            $connection->beginTransaction();
            try {
                $employeeLock = $connection->prepare(
                    "SELECT id FROM usuarios WHERE id = :id AND rol = 'empleado' FOR UPDATE"
                );
                $employeeLock->execute(['id' => $employeeId]);
                if ($employeeLock->fetchColumn() === false) {
                    $connection->commit();
                    continue;
                }

                $shift = getEmployeeShiftForDate($connection, $employeeId, $workDate);
                if ($shift === null || !$shift['works_today'] || $shift['end_at'] > $now->setTimezone($madrid)) {
                    $connection->commit();
                    continue;
                }

                $approvedMinutes = min(720, getApprovedOvertimeMinutes(
                    $connection,
                    $employeeId,
                    $workDate->format('Y-m-d')
                ));
                $closeAt = $shift['end_at']->modify('+' . $approvedMinutes . ' minutes');
                if ($closeAt > $now->setTimezone($madrid)) {
                    $connection->commit();
                    continue;
                }

                $recordsQuery = $connection->prepare(
                    'SELECT event_type, recorded_at_utc
                     FROM time_records
                     WHERE usuario_id = :usuario_id
                       AND recorded_at_utc >= :start_utc
                       AND recorded_at_utc <= :end_utc
                     ORDER BY recorded_at_utc ASC, id ASC
                     FOR UPDATE'
                );
                $recordsQuery->execute([
                    'usuario_id' => $employeeId,
                    'start_utc' => $shift['start_at']->setTimezone($utc)->format('Y-m-d H:i:s.u'),
                    'end_utc' => $closeAt->setTimezone($utc)->format('Y-m-d H:i:s.u'),
                ]);

                $state = 'off';
                $workStartedAt = null;
                $workedSeconds = 0;
                $invalidSequence = false;
                foreach ($recordsQuery->fetchAll() as $record) {
                    $recordedAt = new DateTimeImmutable($record['recorded_at_utc'], $utc);
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
                            $workedSeconds += (int) ($recordedAt->format('U') - $workStartedAt->format('U'));
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
                            if (!in_array($state, ['working', 'paused'], true)) {
                                $invalidSequence = true;
                                break 2;
                            }
                            if ($state === 'working' && $workStartedAt !== null) {
                                $workedSeconds += (int) ($recordedAt->format('U') - $workStartedAt->format('U'));
                            }
                            $state = 'off';
                            $workStartedAt = null;
                            break;
                    }
                }

                if ($invalidSequence) {
                    $sequenceKey = $employeeId . ':' . $workDate->format('Y-m-d');
                    if (!isset($reportedInvalidSequences[$sequenceKey])) {
                        error_log('No se cerró automáticamente una secuencia de fichajes inválida para el empleado ' . $employeeId . ' en ' . $workDate->format('Y-m-d') . '.');
                        $reportedInvalidSequences[$sequenceKey] = true;
                    }
                    $connection->commit();
                    continue;
                }
                if (!in_array($state, ['working', 'paused'], true)) {
                    $connection->commit();
                    continue;
                }
                if ($state === 'working' && $workStartedAt !== null) {
                    $workedSeconds += (int) ($closeAt->setTimezone($utc)->format('U') - $workStartedAt->format('U'));
                }

                $workedSeconds = max(0, $workedSeconds);
                $overtimeSeconds = max(0, $workedSeconds - 8 * 60 * 60);
                $insert = $connection->prepare(
                    "INSERT INTO time_records
                        (employee_code, usuario_id, event_type, recorded_at_utc, client_time_utc, time_zone,
                         latitud, longitud, horas_trabajadas_segundos, horas_extra_segundos,
                         es_hora_extra, es_cierre_automatico)
                     VALUES
                        (:employee_code, :usuario_id, 'exit', :recorded_at_utc, :client_time_utc, 'Europe/Madrid',
                         NULL, NULL, :worked_seconds, :overtime_seconds, :is_overtime, TRUE)"
                );
                $closeAtUtc = $closeAt->setTimezone($utc)->format('Y-m-d H:i:s.u');
                $insert->execute([
                    'employee_code' => 'user:' . $employeeId,
                    'usuario_id' => $employeeId,
                    'recorded_at_utc' => $closeAtUtc,
                    'client_time_utc' => $closeAtUtc,
                    'worked_seconds' => $workedSeconds,
                    'overtime_seconds' => $overtimeSeconds,
                    'is_overtime' => $overtimeSeconds > 0 ? 1 : 0,
                ]);
                $connection->commit();
                $closedCount++;
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $exception;
            }
        }
    }

    return $closedCount;
}

while (true) {
    try {
        $connection = getDatabaseConnection();
        $closedCount = closeExpiredShifts($connection, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($closedCount > 0) {
            error_log('Jornadas cerradas automáticamente: ' . $closedCount . '.');
        }
    } catch (Throwable $exception) {
        error_log('Falló el proceso de cierre automático de jornadas: ' . $exception->getMessage());
    }
    sleep(60);
}
