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
            background: #f5f2ee;
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
            border: 1px solid #e7ded5;
            border-radius: 20px;
            background: #fffdfa;
            box-shadow: 0 16px 40px rgb(67 54 43 / 8%);
        }

        h1 { margin: 0; font-size: 1.8rem; }
        .subtitle { margin: 8px 0 28px; color: #766f68; }
        label { display: block; margin: 18px 0 7px; font-size: .92rem; font-weight: 650; }

        input {
            width: 100%;
            min-height: 46px;
            padding: 10px 12px;
            border: 1px solid #cfc4b9;
            border-radius: 9px;
            color: inherit;
            font: inherit;
        }

        input:focus-visible { outline: 3px solid #d5dfd1; border-color: #82927d; }

        .password-field {
            position: relative;
            display: block;
        }
        .password-field input { padding-right: 52px; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            display: grid;
            width: 40px;
            min-height: 38px;
            margin: 0;
            padding: 8px;
            transform: translateY(-50%);
            place-items: center;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: #766f68;
            cursor: pointer;
        }
        .password-toggle:hover { transform: translateY(-50%); background: #e8eee5; }
        .password-toggle:focus-visible { outline: 3px solid #d5dfd1; outline-offset: 1px; }
        .password-toggle svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.8; }
        .password-toggle .eye-slash { display: none; }
        .password-toggle[aria-pressed="true"] .eye-slash { display: block; }
        .password-toggle[aria-pressed="true"] .eye-open { display: none; }

        button {
            width: 100%;
            min-height: 48px;
            margin-top: 24px;
            border: 0;
            border-radius: 9px;
            background: #82927d;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover { background: #6f806a; }
        button:focus-visible { outline: 3px solid #d5dfd1; outline-offset: 3px; }
        .error { margin: 18px 0 0; color: #b96f5d; font-size: .92rem; }

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
            <span class="password-field">
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
                <button
                    class="password-toggle"
                    id="password-toggle"
                    type="button"
                    aria-label="Mostrar contraseña"
                    aria-pressed="false"
                    aria-controls="password"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path class="eye-open" d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                        <circle class="eye-open" cx="12" cy="12" r="3"></circle>
                        <path class="eye-slash" d="M3 3l18 18M10.6 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a16 16 0 0 1-3.2 4.1M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10.5 10.5 0 0 0 4.2-.9"></path>
                        <path class="eye-slash" d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                    </svg>
                </button>
            </span>

            <button type="submit">Entrar</button>
        </form>

        <?php if ($errorMessage !== ''): ?>
            <p class="error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
    </main>
    <script>
        const passwordInput = document.querySelector("#password");
        const passwordToggle = document.querySelector("#password-toggle");

        passwordToggle.addEventListener("click", () => {
            const isVisible = passwordInput.type === "text";
            passwordInput.type = isVisible ? "password" : "text";
            passwordToggle.setAttribute("aria-pressed", String(!isVisible));
            passwordToggle.setAttribute(
                "aria-label",
                isVisible ? "Mostrar contraseña" : "Ocultar contraseña"
            );
        });
    </script>
</body>
</html>
