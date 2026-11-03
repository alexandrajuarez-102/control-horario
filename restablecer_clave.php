<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$user = getAuthenticatedUser();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Inicia sesión para continuar.']);
    exit;
}

if ($user['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'No tienes permisos para restablecer contraseñas.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($csrfToken)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    http_response_code(403);
    echo json_encode(['error' => 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.']);
    exit;
}

$targetUserId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
$password = $_POST['password'] ?? null;
if (
    $targetUserId === false
    || $targetUserId < 1
    || !is_string($password)
    || strlen($password) < 7
    || strlen($password) > 4096
) {
    http_response_code(400);
    echo json_encode(['error' => 'Indica un usuario válido y una contraseña de entre 7 y 4096 bytes.']);
    exit;
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $statement = $connection->prepare(
        'UPDATE usuarios
         SET password = :password
         WHERE id = :id'
    );
    $statement->execute([
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $targetUserId,
    ]);

    if ($statement->rowCount() !== 1) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró el usuario indicado.']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (PDOException $exception) {
    error_log('No se pudo restablecer la contraseña del usuario: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo restablecer la contraseña. Inténtalo más tarde.']);
}
