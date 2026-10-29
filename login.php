<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

if (getAuthenticatedUser() !== null) {
    header('Location: index.php', true, 303);
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $email = $_POST['correo'] ?? '';
    $password = $_POST['password'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        http_response_code(400);
        $errorMessage = 'La solicitud ha caducado. Recarga la página e inténtalo de nuevo.';
    } elseif (
        !is_string($email)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 254
        || !is_string($password)
        || $password === ''
        || strlen($password) > 4096
    ) {
        $errorMessage = 'Correo o contraseña incorrectos.';
    } else {
        require_once __DIR__ . '/conexion.php';

        try {
            $connection = getDatabaseConnection();
            $statement = $connection->prepare(
                'SELECT id, rol, nombre, correo, password
                 FROM usuarios
                 WHERE correo = :correo
                 LIMIT 1'
            );
            $statement->execute(['correo' => $email]);
            $user = $statement->fetch();
        } catch (PDOException $exception) {
            error_log('No se pudo consultar el usuario durante el login: ' . $exception->getMessage());
            http_response_code(500);
            $errorMessage = 'No se pudo iniciar sesión. Inténtalo más tarde.';
            $user = false;
        }

        if ($user !== false && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $user['id'];
            $_SESSION['rol'] = $user['rol'];
            $_SESSION['nombre'] = $user['nombre'];
            $_SESSION['correo'] = $user['correo'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: index.php', true, 303);
            exit;
        }

        if ($errorMessage === '') {
            $errorMessage = 'Correo o contraseña incorrectos.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $errorMessage = 'Método no permitido.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acceso al sistema de control horario">
    <title>Iniciar sesión · Control horario</title>
    <style>
        :root {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #172033;
            background: #f3f6fb;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            margin: 0;
            padding: 24px;
        }

        main {
            width: min(100%, 420px);
            padding: 36px;
            border: 1px solid #e3e9f2;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 16px 40px rgb(25 42 70 / 7%);
        }

        h1 { margin: 0; font-size: 1.8rem; }
        .subtitle { margin: 8px 0 28px; color: #64718a; }
        label { display: block; margin: 18px 0 7px; font-size: .92rem; font-weight: 650; }

        input {
            width: 100%;
            min-height: 46px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            color: inherit;
            font: inherit;
        }

        input:focus-visible { outline: 3px solid #b5caff; border-color: #3156c9; }

        button {
            width: 100%;
            min-height: 48px;
            margin-top: 24px;
            border: 0;
            border-radius: 9px;
            background: #3156c9;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover { background: #2848ad; }
        button:focus-visible { outline: 3px solid #91b4ff; outline-offset: 3px; }
        .error { margin: 18px 0 0; color: #b42318; font-size: .92rem; }

        @media (max-width: 480px) {
            main { padding: 28px 22px; }
        }
    </style>
</head>
<body>
    <main>
        <h1>Iniciar sesión</h1>
        <p class="subtitle">Accede a tu control horario.</p>

        <form action="login.php" method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
            >

            <label for="correo">Correo electrónico</label>
            <input
                id="correo"
                name="correo"
                type="email"
                autocomplete="username"
                maxlength="254"
                required
            >

            <label for="password">Contraseña</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Entrar</button>
        </form>

        <?php if ($errorMessage !== ''): ?>
            <p class="error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
