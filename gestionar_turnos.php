<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function redirectToSchedule(string $status): void
{
    header('Location: index.php?status=' . rawurlencode($status) . '#schedules', true, 303);
    exit;
}

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$user = getAuthenticatedUser();
if ($user === null) {
    header('Location: login.php', true, 303);
    exit;
}
if ($user['rol'] !== 'admin') {
    http_response_code(403);
    exit('No tienes permisos para asignar turnos.');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($csrfToken)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    http_response_code(403);
    exit('La sesión ha caducado. Recarga la página e inténtalo de nuevo.');
}

$action = $_POST['action'] ?? null;
if ($action === 'delete') {
    $assignmentId = filter_var($_POST['asignacion_id'] ?? null, FILTER_VALIDATE_INT);
    if ($assignmentId === false || $assignmentId < 1) {
        redirectToSchedule('shift_invalid');
    }

    require_once __DIR__ . '/conexion.php';

    try {
        $connection = getDatabaseConnection();
        $delete = $connection->prepare('DELETE FROM asignaciones_turno WHERE id = :id');
        $delete->execute(['id' => $assignmentId]);
        redirectToSchedule($delete->rowCount() === 1 ? 'shift_deleted' : 'shift_invalid');
    } catch (PDOException $exception) {
        error_log('No se pudo eliminar la asignación del turno: ' . $exception->getMessage());
        redirectToSchedule('shift_error');
    }
}

if ($action === 'update') {
    $assignmentId = filter_var($_POST['asignacion_id'] ?? null, FILTER_VALIDATE_INT);
    $employeeId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
    $templateId = filter_var($_POST['turno_id'] ?? null, FILTER_VALIDATE_INT);
    $startDate = $_POST['fecha_inicio'] ?? null;
    $endDate = $_POST['fecha_fin'] ?? '';
    $breakHours = $_POST['horas_descanso'] ?? null;

    if (
        $assignmentId === false
        || $assignmentId < 1
        || $employeeId === false
        || $employeeId < 1
        || $templateId === false
        || $templateId < 1
        || !is_string($startDate)
        || !is_string($endDate)
        || !isValidDate($startDate)
        || ($endDate !== '' && (!isValidDate($endDate) || $endDate < $startDate))
        || !is_string($breakHours)
        || preg_match('/^(?:\d{1,2})(?:\.\d{1,2})?$/', $breakHours) !== 1
        || (float) $breakHours < 0
        || (float) $breakHours > 24
    ) {
        redirectToSchedule('shift_invalid');
    }

    require_once __DIR__ . '/conexion.php';

    try {
        $connection = getDatabaseConnection();
        $connection->beginTransaction();

        $assignmentQuery = $connection->prepare(
            'SELECT usuario_id, turno_id, fecha_inicio, ciclo_inicio
             FROM asignaciones_turno
             WHERE id = :id
             FOR UPDATE'
        );
        $assignmentQuery->execute(['id' => $assignmentId]);
        $currentAssignment = $assignmentQuery->fetch();
        if ($currentAssignment === false) {
            $connection->rollBack();
            redirectToSchedule('shift_invalid');
        }

        $employeeLocks = $connection->prepare(
            "SELECT id
             FROM usuarios
             WHERE id IN (?, ?)
               AND rol = 'empleado'
             ORDER BY id
             FOR UPDATE"
        );
        $employeeLocks->execute([(int) $currentAssignment['usuario_id'], $employeeId]);
        $lockedEmployees = $employeeLocks->fetchAll(PDO::FETCH_COLUMN);
        $expectedLocks = (int) $currentAssignment['usuario_id'] === $employeeId ? 1 : 2;
        if (count($lockedEmployees) !== $expectedLocks) {
            $connection->rollBack();
            redirectToSchedule('shift_invalid');
        }

        $shiftQuery = $connection->prepare(
            'SELECT turnos.id
             FROM plantillas_turnos
             JOIN turnos ON turnos.id = plantillas_turnos.turno_id
             WHERE plantillas_turnos.id = :id AND turnos.activo = TRUE'
        );
        $shiftQuery->execute(['id' => $templateId]);
        $shiftId = $shiftQuery->fetchColumn();
        if ($shiftId === false) {
            $connection->rollBack();
            redirectToSchedule('shift_invalid');
        }

        $overlapQuery = $connection->prepare(
            'SELECT id
             FROM asignaciones_turno
             WHERE usuario_id = :usuario_id
               AND id <> :asignacion_id
               AND fecha_inicio <= :fecha_fin
               AND (fecha_fin IS NULL OR fecha_fin >= :fecha_inicio)
             LIMIT 1'
        );
        $overlapQuery->execute([
            'usuario_id' => $employeeId,
            'asignacion_id' => $assignmentId,
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate === '' ? '9999-12-31' : $endDate,
        ]);
        if ($overlapQuery->fetchColumn() !== false) {
            $connection->rollBack();
            redirectToSchedule('shift_invalid');
        }

        $cycleStart = (int) $currentAssignment['usuario_id'] === $employeeId
            && (int) $currentAssignment['turno_id'] === (int) $shiftId
            && $currentAssignment['fecha_inicio'] === $startDate
            ? $currentAssignment['ciclo_inicio']
            : $startDate;
        $updateAssignment = $connection->prepare(
            'UPDATE asignaciones_turno
             SET usuario_id = :usuario_id,
                 turno_id = :turno_id,
                 fecha_inicio = :fecha_inicio,
                 fecha_fin = :fecha_fin,
                 ciclo_inicio = :ciclo_inicio
             WHERE id = :id'
        );
        $updateAssignment->execute([
            'usuario_id' => $employeeId,
            'turno_id' => $shiftId,
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate === '' ? null : $endDate,
            'ciclo_inicio' => $cycleStart,
            'id' => $assignmentId,
        ]);

        $updateTemplate = $connection->prepare(
            'UPDATE plantillas_turnos
             SET horas_descanso = :horas_descanso
             WHERE id = :id'
        );
        $updateTemplate->execute([
            'horas_descanso' => number_format((float) $breakHours, 2, '.', ''),
            'id' => $templateId,
        ]);
        $connection->commit();

        redirectToSchedule('shift_updated');
    } catch (PDOException $exception) {
        if (isset($connection) && $connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('No se pudo actualizar la asignación del turno: ' . $exception->getMessage());
        redirectToSchedule('shift_error');
    }
}

$employeeId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
$templateId = filter_var($_POST['turno_id'] ?? null, FILTER_VALIDATE_INT);
$startDate = $_POST['fecha_inicio'] ?? null;
$endDate = $_POST['fecha_fin'] ?? '';

if (
    $employeeId === false
    || $employeeId < 1
    || $templateId === false
    || $templateId < 1
    || !is_string($startDate)
    || !is_string($endDate)
    || !isValidDate($startDate)
    || ($endDate !== '' && (!isValidDate($endDate) || $endDate < $startDate))
) {
    redirectToSchedule('shift_invalid');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $connection->beginTransaction();
    $employeeQuery = $connection->prepare(
        "SELECT id FROM usuarios WHERE id = :id AND rol = 'empleado' FOR UPDATE"
    );
    $employeeQuery->execute(['id' => $employeeId]);
    if ($employeeQuery->fetchColumn() === false) {
        $connection->rollBack();
        redirectToSchedule('shift_invalid');
    }

    $shiftQuery = $connection->prepare(
        'SELECT turnos.id
         FROM plantillas_turnos
         JOIN turnos ON turnos.id = plantillas_turnos.turno_id
         WHERE plantillas_turnos.id = :id AND turnos.activo = TRUE'
    );
    $shiftQuery->execute(['id' => $templateId]);
    $shiftId = $shiftQuery->fetchColumn();
    if ($shiftId === false) {
        $connection->rollBack();
        redirectToSchedule('shift_invalid');
    }

    $overlapQuery = $connection->prepare(
        'SELECT id
         FROM asignaciones_turno
         WHERE usuario_id = :usuario_id
           AND fecha_inicio <= :fecha_fin
           AND (fecha_fin IS NULL OR fecha_fin >= :fecha_inicio)
         LIMIT 1'
    );
    $overlapQuery->execute([
        'usuario_id' => $employeeId,
        'fecha_inicio' => $startDate,
        'fecha_fin' => $endDate === '' ? '9999-12-31' : $endDate,
    ]);
    if ($overlapQuery->fetchColumn() !== false) {
        $connection->rollBack();
        redirectToSchedule('shift_invalid');
    }

    $insert = $connection->prepare(
        'INSERT INTO asignaciones_turno
            (usuario_id, turno_id, fecha_inicio, fecha_fin, ciclo_inicio, asignado_por)
         VALUES
            (:usuario_id, :turno_id, :fecha_inicio, :fecha_fin, :ciclo_inicio, :asignado_por)'
    );
    $insert->execute([
        'usuario_id' => $employeeId,
        'turno_id' => $shiftId,
        'fecha_inicio' => $startDate,
        'fecha_fin' => $endDate === '' ? null : $endDate,
        'ciclo_inicio' => $startDate,
        'asignado_por' => $user['id'],
    ]);
    $connection->commit();

    redirectToSchedule('shift_created');
} catch (PDOException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('No se pudo asignar el turno: ' . $exception->getMessage());
    redirectToSchedule('shift_error');
}
