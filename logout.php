<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

function destroyCurrentSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookieParameters['path'],
            'domain' => $cookieParameters['domain'],
            'secure' => $cookieParameters['secure'],
            'httponly' => $cookieParameters['httponly'],
            'samesite' => $cookieParameters['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

$user = getAuthenticatedUser();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: ' . ($user === null ? 'login.php' : 'index.php'), true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$submittedToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($submittedToken)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    if ($user === null) {
        destroyCurrentSession();
        header('Location: login.php', true, 303);
        exit;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('La solicitud ha caducado. Vuelve al panel e inténtalo de nuevo.');
}

destroyCurrentSession();
header('Location: login.php', true, 303);
exit;
