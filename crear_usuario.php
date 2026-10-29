<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function redirectToAdmin(string $status): void
{
    header('Location: index.php?status=' . rawurlencode($status), true, 303);
    exit;
}

function hasLengthBetween(string $value, int $minimum, int $maximum): bool
{
    $matched = preg_match_all('/./us', $value);
    return $matched !== false && $matched >= $minimum && $matched <= $maximum;
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
    exit('No tienes permisos para crear usuarios.');
}

$submittedToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($submittedToken)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    http_response_code(403);
    exit('La sesión ha caducado. Recarga la página e inténtalo de nuevo.');
}

$name = $_POST['nombre'] ?? null;
$dni = $_POST['dni'] ?? null;
$address = $_POST['direccion'] ?? null;
$email = $_POST['correo'] ?? null;
$password = $_POST['password'] ?? null;
$role = $_POST['rol'] ?? null;

if (
    !is_string($name)
    || !is_string($dni)
    || !is_string($address)
    || !is_string($email)
    || !is_string($password)
    || !is_string($role)
) {
    redirectToAdmin('invalid');
}

$name = trim($name);
$dni = trim($dni);
$address = trim($address);
$email = trim($email);

if (
    !hasLengthBetween($name, 1, 150)
    || ($dni !== '' && !hasLengthBetween($dni, 1, 20))
    || ($address !== '' && !hasLengthBetween($address, 1, 255))
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || strlen($email) > 254
    || strlen($password) < 7
    || strlen($password) > 4096
    || !in_array($role, ['admin', 'empleado'], true)
) {
    redirectToAdmin('invalid');
}

require_once __DIR__ . '/conexion.php';

try {
    $connection = getDatabaseConnection();
    $statement = $connection->prepare(
        'INSERT INTO usuarios (rol, nombre, dni, direccion, correo, password)
         VALUES (:rol, :nombre, :dni, :direccion, :correo, :password)'
    );
    $statement->execute([
        'rol' => $role,
        'nombre' => $name,
        'dni' => $dni === '' ? null : $dni,
        'direccion' => $address === '' ? null : $address,
        'correo' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    redirectToAdmin('created');
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        redirectToAdmin('duplicate');
    }

    error_log('No se pudo crear el usuario: ' . $exception->getMessage());
    redirectToAdmin('error');
}
