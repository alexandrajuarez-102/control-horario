<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/conexion.php';

$name = getenv('ADMIN_NAME');
$email = getenv('ADMIN_EMAIL');
$password = getenv('ADMIN_PASSWORD');

if (
    !is_string($name)
    || trim($name) === ''
    || strlen($name) > 150
    || !is_string($email)
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($email) > 254
    || !is_string($password)
    || strlen($password) < 7
    || strlen($password) > 4096
) {
    fwrite(STDERR, "Define ADMIN_NAME, ADMIN_EMAIL y ADMIN_PASSWORD válidos; la contraseña requiere al menos 7 caracteres.\n");
    exit(1);
}

$connection = getDatabaseConnection();
$lockResult = $connection->query("SELECT GET_LOCK('control_horario_initial_admin', 10)")->fetchColumn();
if ((int) $lockResult !== 1) {
    fwrite(STDERR, "No se pudo obtener el bloqueo para inicializar el administrador.\n");
    exit(1);
}

try {
    $connection->beginTransaction();
    $userCount = (int) $connection->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    if ($userCount > 0) {
        throw new RuntimeException('Ya existen usuarios; no se creó otro administrador inicial.');
    }

    $statement = $connection->prepare(
        'INSERT INTO usuarios (rol, nombre, correo, password)
         VALUES (:rol, :nombre, :correo, :password)'
    );
    $statement->execute([
        'rol' => 'admin',
        'nombre' => trim($name),
        'correo' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $connection->commit();
    fwrite(STDOUT, "Administrador inicial creado correctamente.\n");
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
} finally {
    $connection->query("SELECT RELEASE_LOCK('control_horario_initial_admin')");
}
