<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function redirectToTemplates(string $status): void
{
    header('Location: index.php?status=' . rawurlencode($status) . '#shift-templates', true, 303);
    exit;
}

function templateTextLength(string $value): int
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
if ($user['rol'] !== 'admin') {
    http_response_code(403);
    exit('No tienes permisos para crear plantillas de turno.');
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

$name = trim(is_string($_POST['nombre_turno'] ?? null) ? $_POST['nombre_turno'] : '');
$type = $_POST['tipo'] ?? null;
$startTime = $_POST['hora_inicio'] ?? null;
$endTime = $_POST['hora_fin'] ?? null;
$workDays = filter_var($_POST['dias_trabajo'] ?? null, FILTER_VALIDATE_INT);
$restDays = filter_var($_POST['dias_descanso'] ?? null, FILTER_VALIDATE_INT);
$validTime = static fn ($value): bool => is_string($value)
    && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;

if (
    templateTextLength($name) < 1
    || templateTextLength($name) > 100
    || !in_array($type, ['fijo', 'rotativo'], true)
    || !$validTime($startTime)
    || !$validTime($endTime)
    || $startTime === $endTime
    || $workDays === false
    || $workDays < 1
    || $workDays > 31
    || $restDays === false
    || $restDays < 1
    || $restDays > 31
) {
    redirectToTemplates('template_invalid');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $connection->beginTransaction();
    $insertShift = $connection->prepare(
        'INSERT INTO turnos (nombre, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso)
         VALUES (:nombre, :tipo, :hora_inicio, :hora_fin, :dias_trabajo, :dias_descanso)'
    );
    $insertShift->execute([
        'nombre' => $name,
        'tipo' => $type,
        'hora_inicio' => $startTime . ':00',
        'hora_fin' => $endTime . ':00',
        'dias_trabajo' => $workDays,
        'dias_descanso' => $restDays,
    ]);

    $insertTemplate = $connection->prepare(
        'INSERT INTO plantillas_turnos
            (nombre_turno, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso, turno_id)
         VALUES
            (:nombre_turno, :tipo, :hora_inicio, :hora_fin, :dias_trabajo, :dias_descanso, :turno_id)'
    );
    $insertTemplate->execute([
        'nombre_turno' => $name,
        'tipo' => $type,
        'hora_inicio' => $startTime . ':00',
        'hora_fin' => $endTime . ':00',
        'dias_trabajo' => $workDays,
        'dias_descanso' => $restDays,
        'turno_id' => $connection->lastInsertId(),
    ]);
    $connection->commit();

    redirectToTemplates('template_created');
} catch (PDOException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if ($exception->getCode() === '23000') {
        redirectToTemplates('template_duplicate');
    }
    error_log('No se pudo crear la plantilla de turno: ' . $exception->getMessage());
    redirectToTemplates('template_error');
}
