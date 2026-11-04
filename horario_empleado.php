<?php
declare(strict_types=1);

function getEmployeeShiftForDate(PDO $connection, int $employeeId, DateTimeImmutable $workDate): ?array
{
    $query = $connection->prepare(
        'SELECT asignaciones_turno.fecha_inicio, asignaciones_turno.ciclo_inicio,
                turnos.nombre, turnos.hora_inicio, turnos.hora_fin,
                turnos.dias_trabajo, turnos.dias_descanso
         FROM asignaciones_turno
         JOIN turnos ON turnos.id = asignaciones_turno.turno_id
         WHERE asignaciones_turno.usuario_id = :usuario_id
           AND asignaciones_turno.fecha_inicio <= :fecha_inicio
           AND (asignaciones_turno.fecha_fin IS NULL OR asignaciones_turno.fecha_fin >= :fecha_fin)
         ORDER BY asignaciones_turno.fecha_inicio DESC, asignaciones_turno.id DESC
         LIMIT 1'
    );
    $date = $workDate->format('Y-m-d');
    $query->execute([
        'usuario_id' => $employeeId,
        'fecha_inicio' => $date,
        'fecha_fin' => $date,
    ]);
    $assignment = $query->fetch();
    if ($assignment === false) {
        return null;
    }

    $cycleStart = new DateTimeImmutable($assignment['ciclo_inicio'], $workDate->getTimezone());
    $cycleOffset = (int) $cycleStart->diff($workDate)->format('%r%a');
    $cycleLength = (int) $assignment['dias_trabajo'] + (int) $assignment['dias_descanso'];
    $assignment['works_today'] = $cycleOffset >= 0
        && ($cycleOffset % $cycleLength) < (int) $assignment['dias_trabajo'];

    if ($assignment['works_today']) {
        $shiftStart = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' ' . $assignment['hora_inicio'],
            $workDate->getTimezone()
        );
        $shiftEnd = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' ' . $assignment['hora_fin'],
            $workDate->getTimezone()
        );
        if ($shiftStart === false || $shiftEnd === false) {
            throw new RuntimeException('No se pudo interpretar el horario asignado.');
        }
        if ($shiftEnd <= $shiftStart) {
            $shiftEnd = $shiftEnd->modify('+1 day');
        }
        $assignment['start_at'] = $shiftStart;
        $assignment['end_at'] = $shiftEnd;
    }

    return $assignment;
}

function getApprovedOvertimeMinutes(PDO $connection, int $employeeId, string $date): int
{
    $query = $connection->prepare(
        "SELECT COALESCE(SUM(minutos), 0)
         FROM solicitudes_horas_extra
         WHERE usuario_id = :usuario_id AND fecha = :fecha AND estado = 'aprobada'"
    );
    $query->execute(['usuario_id' => $employeeId, 'fecha' => $date]);
    return (int) $query->fetchColumn();
}

function getLatestAttendanceEventSince(
    PDO $connection,
    int $employeeId,
    DateTimeImmutable $workDate,
    DateTimeImmutable $through
): ?string {
    $query = $connection->prepare(
        'SELECT event_type
         FROM time_records
         WHERE usuario_id = :usuario_id
           AND recorded_at_utc >= :workday_start_utc
           AND recorded_at_utc <= :through_utc
         ORDER BY recorded_at_utc DESC, id DESC
         LIMIT 1'
    );
    $query->execute([
        'usuario_id' => $employeeId,
        'workday_start_utc' => $workDate->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        'through_utc' => $through->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
    ]);
    $event = $query->fetchColumn();

    return $event === false ? null : $event;
}
