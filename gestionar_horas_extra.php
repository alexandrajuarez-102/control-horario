<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/horario_empleado.php';
startSecureSession();

function redirectToOvertime(string $status): void
{
    header('Location: index.php?status=' . rawurlencode($status) . '#overtime-requests', true, 303);
    exit;
}

function isValidOvertimeDate(string $value): bool
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
    redirectToOvertime('overtime_invalid');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();

    if ($action === 'request' && $user['rol'] === 'empleado') {
        $dateValue = $_POST['fecha'] ?? null;
        $minutes = filter_var($_POST['minutos'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim(is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '');
        $reasonLength = preg_match_all('/./us', $reason);
        if (
            !is_string($dateValue)
            || !isValidOvertimeDate($dateValue)
            || $minutes === false
            || $minutes < 1
            || $minutes > 720
            || $reasonLength === false
            || $reasonLength < 1
            || $reasonLength > 1000
        ) {
            redirectToOvertime('overtime_invalid');
        }

        $madrid = new DateTimeZone('Europe/Madrid');
        $workDate = new DateTimeImmutable($dateValue, $madrid);
        $today = new DateTimeImmutable('today', $madrid);

        $connection->beginTransaction();
        $userLock = $connection->prepare(
            "SELECT id FROM usuarios WHERE id = :id AND rol = 'empleado' FOR UPDATE"
        );
        $userLock->execute(['id' => $user['id']]);
        if ($userLock->fetchColumn() === false) {
            $connection->rollBack();
            redirectToOvertime('overtime_invalid');
        }

        $assignment = getEmployeeShiftForDate($connection, $user['id'], $workDate);
        if ($assignment === null || !$assignment['works_today']) {
            $connection->rollBack();
            redirectToOvertime('overtime_no_shift');
        }
        if ($workDate < $today) {
            $latestEvent = getLatestAttendanceEventSince(
                $connection,
                (int) $user['id'],
                $workDate,
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );
            if (
                $workDate->diff($today)->days !== 1
                || $assignment['end_at']->format('Y-m-d') !== $today->format('Y-m-d')
                || !in_array($latestEvent, ['entry', 'pausa', 'fin_pausa'], true)
            ) {
                $connection->rollBack();
                redirectToOvertime('overtime_invalid');
            }
        }

        $pendingQuery = $connection->prepare(
            "SELECT id
             FROM solicitudes_horas_extra
             WHERE usuario_id = :usuario_id AND fecha = :fecha AND estado = 'pendiente'
             LIMIT 1
             FOR UPDATE"
        );
        $pendingQuery->execute(['usuario_id' => $user['id'], 'fecha' => $dateValue]);
        if ($pendingQuery->fetchColumn() !== false) {
            $connection->rollBack();
            redirectToOvertime('overtime_pending');
        }

        $insert = $connection->prepare(
            "INSERT INTO solicitudes_horas_extra (usuario_id, fecha, minutos, motivo)
             VALUES (:usuario_id, :fecha, :minutos, :motivo)"
        );
        $insert->execute([
            'usuario_id' => $user['id'],
            'fecha' => $dateValue,
            'minutos' => $minutes,
            'motivo' => $reason,
        ]);
        $connection->commit();
        redirectToOvertime('overtime_requested');
    }

    if ($action === 'review' && $user['rol'] === 'admin') {
        $requestId = filter_var($_POST['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? null;
        if ($requestId === false || $requestId < 1 || !in_array($decision, ['aprobada', 'rechazada'], true)) {
            redirectToOvertime('overtime_invalid');
        }

        $connection->beginTransaction();
        $ownerQuery = $connection->prepare(
            'SELECT usuario_id FROM solicitudes_horas_extra WHERE id = :id'
        );
        $ownerQuery->execute(['id' => $requestId]);
        $employeeId = $ownerQuery->fetchColumn();
        if ($employeeId === false) {
            $connection->rollBack();
            redirectToOvertime('overtime_invalid');
        }

        $employeeLock = $connection->prepare(
            "SELECT id FROM usuarios WHERE id = :id AND rol = 'empleado' FOR UPDATE"
        );
        $employeeLock->execute(['id' => $employeeId]);
        if ($employeeLock->fetchColumn() === false) {
            $connection->rollBack();
            redirectToOvertime('overtime_invalid');
        }

        $requestQuery = $connection->prepare(
            "SELECT id, usuario_id, fecha, minutos
             FROM solicitudes_horas_extra
             WHERE id = :id AND estado = 'pendiente'
             FOR UPDATE"
        );
        $requestQuery->execute(['id' => $requestId]);
        $request = $requestQuery->fetch();
        if ($request === false) {
            $connection->rollBack();
            redirectToOvertime('overtime_invalid');
        }

        if ($decision === 'aprobada') {
            $approvedMinutes = getApprovedOvertimeMinutes(
                $connection,
                (int) $request['usuario_id'],
                $request['fecha']
            );
            if ($approvedMinutes + (int) $request['minutos'] > 720) {
                $connection->rollBack();
                redirectToOvertime('overtime_limit');
            }
        }

        $update = $connection->prepare(
            "UPDATE solicitudes_horas_extra
             SET estado = :estado, revisado_por = :revisado_por, revisado_en_utc = UTC_TIMESTAMP(6)
             WHERE id = :id AND estado = 'pendiente'"
        );
        $update->execute([
            'estado' => $decision,
            'revisado_por' => $user['id'],
            'id' => $requestId,
        ]);
        $connection->commit();
        redirectToOvertime('overtime_reviewed');
    }

    http_response_code(403);
    exit('No tienes permisos para esta operación.');
} catch (PDOException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('No se pudo guardar o revisar la solicitud de horas extra: ' . $exception->getMessage());
    redirectToOvertime('overtime_error');
} catch (RuntimeException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('No se pudo determinar el horario para horas extra: ' . $exception->getMessage());
    redirectToOvertime('overtime_error');
}
