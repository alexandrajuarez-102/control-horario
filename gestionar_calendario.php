<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function redirectToCalendar(string $status): void
{
    header('Location: index.php?status=' . rawurlencode($status) . '#calendar', true, 303);
    exit;
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
    exit('No tienes permisos para crear eventos.');
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

$title = trim(is_string($_POST['titulo'] ?? null) ? $_POST['titulo'] : '');
$date = $_POST['fecha'] ?? null;
$type = $_POST['tipo'] ?? null;
$titleLength = preg_match_all('/./us', $title);
$parsedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
$dateErrors = DateTimeImmutable::getLastErrors();
if (
    $title === ''
    || $titleLength === false
    || $titleLength > 160
    || !is_string($date)
    || $parsedDate === false
    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    || $parsedDate->format('Y-m-d') !== $date
    || !in_array($type, ['evento', 'festivo'], true)
) {
    redirectToCalendar('calendar_invalid');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $insert = $connection->prepare(
        'INSERT INTO calendario_eventos (titulo, fecha, tipo)
         VALUES (:titulo, :fecha, :tipo)'
    );
    $insert->execute(['titulo' => $title, 'fecha' => $date, 'tipo' => $type]);
    redirectToCalendar('calendar_created');
} catch (PDOException $exception) {
    error_log('No se pudo crear el evento de calendario: ' . $exception->getMessage());
    redirectToCalendar('calendar_error');
}
