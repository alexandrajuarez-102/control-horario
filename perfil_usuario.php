<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respondToProfile(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function profileTextLength(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();
if ($user === null) {
    respondToProfile(401, ['error' => 'Inicia sesión para consultar perfiles.']);
}
if ($user['rol'] !== 'admin') {
    respondToProfile(403, ['error' => 'No tienes permisos para consultar perfiles.']);
}

require_once __DIR__ . '/conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $employeeId = filter_var($_GET['usuario_id'] ?? null, FILTER_VALIDATE_INT);
    $month = $_GET['mes'] ?? null;
    if (
        $employeeId === false
        || $employeeId < 1
        || !is_string($month)
        || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1
    ) {
        respondToProfile(400, ['error' => 'El empleado o el mes indicados no son válidos.']);
    }

    $monthStart = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $month . '-01',
        new DateTimeZone('Europe/Madrid')
    );
    if ($monthStart === false || $monthStart->format('Y-m') !== $month) {
        respondToProfile(400, ['error' => 'El mes indicado no es válido.']);
    }
    $monthEnd = $monthStart->modify('+1 month');
    $utc = new DateTimeZone('UTC');

    try {
        $connection = getDatabaseConnection();
        $employeeQuery = $connection->prepare(
            "SELECT id, nombre, cargo, dni, direccion, correo
             FROM usuarios WHERE id = :id AND rol = 'empleado'"
        );
        $employeeQuery->execute(['id' => $employeeId]);
        $employee = $employeeQuery->fetch();
        if ($employee === false) {
            respondToProfile(404, ['error' => 'No se encontró el trabajador indicado.']);
        }

        $recordsQuery = $connection->prepare(
            "SELECT event_type, recorded_at_utc, horas_trabajadas_segundos, horas_extra_segundos,
                    es_cierre_automatico
             FROM time_records
             WHERE usuario_id = :usuario_id
               AND event_type IN ('entry', 'exit')
               AND recorded_at_utc >= :month_start
               AND recorded_at_utc < :month_end
             ORDER BY recorded_at_utc ASC, id ASC"
        );
        $recordsQuery->execute([
            'usuario_id' => $employeeId,
            'month_start' => $monthStart->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'month_end' => $monthEnd->setTimezone($utc)->format('Y-m-d H:i:s.u'),
        ]);
        $madrid = new DateTimeZone('Europe/Madrid');
        $records = array_map(
            static function (array $record) use ($madrid): array {
                $recordedAt = (new DateTimeImmutable($record['recorded_at_utc'], new DateTimeZone('UTC')))
                    ->setTimezone($madrid);
                return [
                    'fecha' => $recordedAt->format('d/m/Y'),
                    'tipo' => $record['event_type'] === 'entry' ? 'Entrada' : 'Salida',
                    'cierreAutomatico' => (bool) $record['es_cierre_automatico'],
                    'hora' => $recordedAt->format('H:i:s'),
                    'workedSeconds' => $record['horas_trabajadas_segundos'] === null
                        ? null
                        : (int) $record['horas_trabajadas_segundos'],
                    'overtimeSeconds' => $record['horas_extra_segundos'] === null
                        ? null
                        : (int) $record['horas_extra_segundos'],
                ];
            },
            $recordsQuery->fetchAll()
        );

        respondToProfile(200, ['employee' => $employee, 'records' => $records]);
    } catch (PDOException $exception) {
        error_log('No se pudo cargar el perfil o sus fichajes: ' . $exception->getMessage());
        respondToProfile(500, ['error' => 'No se pudo cargar el perfil. Inténtalo más tarde.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    respondToProfile(405, ['error' => 'Método no permitido.']);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($csrfToken)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    respondToProfile(403, ['error' => 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.']);
}

$employeeId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
$name = trim(is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
$dni = trim(is_string($_POST['dni'] ?? null) ? $_POST['dni'] : '');
$address = trim(is_string($_POST['direccion'] ?? null) ? $_POST['direccion'] : '');
$jobTitle = trim(is_string($_POST['cargo'] ?? null) ? $_POST['cargo'] : '');
$email = trim(is_string($_POST['correo'] ?? null) ? $_POST['correo'] : '');
if (
    $employeeId === false
    || $employeeId < 1
    || profileTextLength($name) < 1
    || profileTextLength($name) > 150
    || ($dni !== '' && profileTextLength($dni) > 20)
    || ($address !== '' && profileTextLength($address) > 255)
    || ($jobTitle !== '' && profileTextLength($jobTitle) > 100)
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || strlen($email) > 254
) {
    respondToProfile(400, ['error' => 'Revisa el nombre, DNI, dirección y correo electrónico.']);
}

try {
    $connection = getDatabaseConnection();
    $connection->beginTransaction();
    $employeeQuery = $connection->prepare(
        "SELECT id FROM usuarios WHERE id = :id AND rol = 'empleado' FOR UPDATE"
    );
    $employeeQuery->execute(['id' => $employeeId]);
    if ($employeeQuery->fetchColumn() === false) {
        $connection->rollBack();
        respondToProfile(404, ['error' => 'No se encontró el trabajador indicado.']);
    }

    $update = $connection->prepare(
        'UPDATE usuarios
         SET nombre = :nombre, cargo = :cargo, dni = :dni, direccion = :direccion, correo = :correo
         WHERE id = :id'
    );
    $update->execute([
        'nombre' => $name,
        'cargo' => $jobTitle === '' ? null : $jobTitle,
        'dni' => $dni === '' ? null : $dni,
        'direccion' => $address === '' ? null : $address,
        'correo' => $email,
        'id' => $employeeId,
    ]);
    $connection->commit();

    respondToProfile(200, [
        'success' => true,
        'employee' => [
            'id' => $employeeId,
            'nombre' => $name,
            'cargo' => $jobTitle === '' ? null : $jobTitle,
            'dni' => $dni === '' ? null : $dni,
            'direccion' => $address === '' ? null : $address,
            'correo' => $email,
        ],
    ]);
} catch (PDOException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if ($exception->getCode() === '23000') {
        respondToProfile(409, ['error' => 'Ya existe un usuario con ese DNI o correo electrónico.']);
    }
    error_log('No se pudo actualizar el perfil del trabajador: ' . $exception->getMessage());
    respondToProfile(500, ['error' => 'No se pudo guardar el perfil. Inténtalo más tarde.']);
}
