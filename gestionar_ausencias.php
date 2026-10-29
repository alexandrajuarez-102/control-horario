<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function redirectToAbsences(string $status): void
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

function textLength(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
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
if (!is_string($action)) {
    redirectToAbsences('absence_invalid');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();

    if ($action === 'request' && $user['rol'] === 'empleado') {
        $type = $_POST['tipo'] ?? null;
        $startDate = $_POST['fecha_inicio'] ?? null;
        $endDate = $_POST['fecha_fin'] ?? null;
        $observations = trim(is_string($_POST['observaciones'] ?? null) ? $_POST['observaciones'] : '');

        if (
            !is_string($type)
            || !in_array($type, ['vacaciones', 'medico', 'asuntos_familiares'], true)
            || !is_string($startDate)
            || !is_string($endDate)
            || !isValidDate($startDate)
            || !isValidDate($endDate)
            || $endDate < $startDate
            || textLength($observations) > 1000
        ) {
            redirectToAbsences('absence_invalid');
        }

        $insert = $connection->prepare(
            "INSERT INTO ausencias
                (usuario_id, tipo, fecha_inicio, fecha_fin, observaciones, estado, creado_por)
             VALUES
                (:usuario_id, :tipo, :fecha_inicio, :fecha_fin, :observaciones, 'pendiente', :creado_por)"
        );
        $insert->execute([
            'usuario_id' => $user['id'],
            'tipo' => $type,
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate,
            'observaciones' => $observations === '' ? null : $observations,
            'creado_por' => $user['id'],
        ]);
        redirectToAbsences('absence_requested');
    }

    if ($action === 'review' && $user['rol'] === 'admin') {
        $absenceId = filter_var($_POST['ausencia_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? null;
        if ($absenceId === false || $absenceId < 1 || !in_array($decision, ['aprobada', 'rechazada'], true)) {
            redirectToAbsences('absence_invalid');
        }

        $update = $connection->prepare(
            "UPDATE ausencias
             SET estado = :estado, revisado_por = :revisado_por, revisado_en_utc = UTC_TIMESTAMP(6)
             WHERE id = :id AND estado = 'pendiente'"
        );
        $update->execute([
            'estado' => $decision,
            'revisado_por' => $user['id'],
            'id' => $absenceId,
        ]);

        redirectToAbsences($update->rowCount() === 1 ? 'absence_reviewed' : 'absence_invalid');
    }

    if ($action === 'manual' && $user['rol'] === 'admin') {
        $employeeId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
        $type = $_POST['tipo'] ?? null;
        $startDate = $_POST['fecha_inicio'] ?? null;
        $endDate = $_POST['fecha_fin'] ?? '';
        $observations = trim(is_string($_POST['observaciones'] ?? null) ? $_POST['observaciones'] : '');

        if (
            $employeeId === false
            || $employeeId < 1
            || !is_string($type)
            || !in_array($type, ['baja_medica', 'retraso'], true)
            || !is_string($startDate)
            || !is_string($endDate)
            || !isValidDate($startDate)
            || ($endDate !== '' && (!isValidDate($endDate) || $endDate < $startDate))
            || ($type === 'retraso' && $endDate !== '' && $endDate !== $startDate)
            || textLength($observations) > 1000
        ) {
            redirectToAbsences('absence_invalid');
        }

        $employeeQuery = $connection->prepare(
            "SELECT id FROM usuarios WHERE id = :id AND rol = 'empleado'"
        );
        $employeeQuery->execute(['id' => $employeeId]);
        if ($employeeQuery->fetchColumn() === false) {
            redirectToAbsences('absence_invalid');
        }

        $insert = $connection->prepare(
            "INSERT INTO ausencias
                (usuario_id, tipo, fecha_inicio, fecha_fin, observaciones, estado, creado_por, revisado_por, revisado_en_utc)
             VALUES
                (:usuario_id, :tipo, :fecha_inicio, :fecha_fin, :observaciones, 'registrada', :creado_por, :revisado_por, UTC_TIMESTAMP(6))"
        );
        $insert->execute([
            'usuario_id' => $employeeId,
            'tipo' => $type,
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate === '' ? $startDate : $endDate,
            'observaciones' => $observations === '' ? null : $observations,
            'creado_por' => $user['id'],
            'revisado_por' => $user['id'],
        ]);
        redirectToAbsences('absence_recorded');
    }

    http_response_code(403);
    exit('No tienes permisos para esta operación.');
} catch (PDOException $exception) {
    error_log('No se pudo guardar o revisar la ausencia: ' . $exception->getMessage());
    redirectToAbsences('absence_error');
}
