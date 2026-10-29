<?php
declare(strict_types=1);

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function getAuthenticatedUser(): ?array
{
    $userId = $_SESSION['usuario_id'] ?? null;
    $name = $_SESSION['nombre'] ?? null;
    $role = $_SESSION['rol'] ?? null;

    if (
        !is_int($userId)
        || $userId < 1
        || !is_string($name)
        || !in_array($role, ['admin', 'empleado'], true)
    ) {
        return null;
    }

    return ['id' => $userId, 'nombre' => $name, 'rol' => $role];
}
