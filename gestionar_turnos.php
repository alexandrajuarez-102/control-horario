<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function redirectToSchedule(string $status): void
{
    header('Location: index.php?status=' . rawurlencode($status), true, 303);
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

$employeeId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
$shiftId = filter_var($_POST['turno_id'] ?? null, FILTER_VALIDATE_INT);
$startDate = $_POST['fecha_inicio'] ?? null;
$endDate = $_POST['fecha_fin'] ?? '';

if (
    $employeeId === false
    || $employeeId < 1
    || $shiftId === false
    || $shiftId < 1
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
        'SELECT id FROM turnos WHERE id = :id AND activo = TRUE'
    );
    $shiftQuery->execute(['id' => $shiftId]);
    if ($shiftQuery->fetchColumn() === false) {
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
