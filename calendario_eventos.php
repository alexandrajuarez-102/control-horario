<?php
declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
startSecureSession();

if (getAuthenticatedUser() === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Inicia sesión para consultar el calendario.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;
if (!is_string($start) || !is_string($end)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'El intervalo del calendario no es válido.']);
    exit;
}

$startDate = substr($start, 0, 10);
$endDate = substr($end, 0, 10);
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
};
if (!$validDate($startDate) || !$validDate($endDate) || $endDate < $startDate) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'El intervalo del calendario no es válido.']);
    exit;
}

require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $query = getDatabaseConnection()->prepare(
        'SELECT id, titulo, fecha, tipo
         FROM calendario_eventos
         WHERE fecha >= :fecha_inicio AND fecha < :fecha_fin
         ORDER BY fecha ASC, id ASC'
    );
    $query->execute(['fecha_inicio' => $startDate, 'fecha_fin' => $endDate]);
    $events = array_map(
        static fn (array $event): array => [
            'id' => (string) $event['id'],
            'title' => $event['titulo'],
            'start' => $event['fecha'],
            'allDay' => true,
            'color' => $event['tipo'] === 'festivo' ? '#b42318' : '#3156c9',
            'extendedProps' => ['tipo' => $event['tipo']],
        ],
        $query->fetchAll()
    );
    echo json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
} catch (PDOException | JsonException $exception) {
    error_log('No se pudo cargar el calendario: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar el calendario.']);
}
