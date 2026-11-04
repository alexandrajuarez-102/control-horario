<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/sesion.php';
startSecureSession();
$user = getAuthenticatedUser();

if ($user === null) {
    header('Location: login.php', true, 303);
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessages = [
    'created' => ['state' => 'success', 'text' => 'Usuario creado correctamente.'],
    'invalid' => ['state' => 'error', 'text' => 'Revisa los datos: comprueba los campos y que la contraseña tenga al menos 7 caracteres.'],
    'duplicate' => ['state' => 'error', 'text' => 'Ya existe un usuario con ese correo o DNI.'],
    'error' => ['state' => 'error', 'text' => 'No se pudo crear el usuario. Inténtalo más tarde.'],
    'shift_created' => ['state' => 'success', 'text' => 'Turno asignado correctamente.'],
    'shift_deleted' => ['state' => 'success', 'text' => 'Asignación de turno eliminada correctamente.'],
    'shift_updated' => ['state' => 'success', 'text' => 'Asignación de turno actualizada correctamente.'],
    'shift_invalid' => ['state' => 'error', 'text' => 'Los datos del turno no son válidos o se solapan con otra asignación.'],
    'shift_error' => ['state' => 'error', 'text' => 'No se pudo guardar la asignación del turno. Inténtalo más tarde.'],
    'template_created' => ['state' => 'success', 'text' => 'Plantilla de turno creada correctamente.'],
    'template_invalid' => ['state' => 'error', 'text' => 'Revisa el nombre, el horario y los días del ciclo.'],
    'template_duplicate' => ['state' => 'error', 'text' => 'Ya existe una plantilla o turno con ese nombre.'],
    'template_error' => ['state' => 'error', 'text' => 'No se pudo crear la plantilla. Inténtalo más tarde.'],
    'calendar_created' => ['state' => 'success', 'text' => 'Evento añadido al calendario compartido.'],
    'calendar_invalid' => ['state' => 'error', 'text' => 'Revisa el título, la fecha y el tipo de evento.'],
    'calendar_error' => ['state' => 'error', 'text' => 'No se pudo guardar el evento. Inténtalo más tarde.'],
    'absence_requested' => ['state' => 'success', 'text' => 'Solicitud enviada para revisión.'],
    'absence_updated' => ['state' => 'success', 'text' => 'Solicitud modificada correctamente.'],
    'absence_deleted' => ['state' => 'success', 'text' => 'Solicitud eliminada correctamente.'],
    'absence_reviewed' => ['state' => 'success', 'text' => 'Solicitud actualizada.'],
    'absence_recorded' => ['state' => 'success', 'text' => 'Incidencia registrada.'],
    'absence_invalid' => ['state' => 'error', 'text' => 'Los datos de la ausencia no son válidos.'],
    'absence_error' => ['state' => 'error', 'text' => 'No se pudo guardar la ausencia. Inténtalo más tarde.'],
    'overtime_requested' => ['state' => 'success', 'text' => 'Solicitud de horas extra enviada al administrador.'],
    'overtime_reviewed' => ['state' => 'success', 'text' => 'Solicitud de horas extra revisada.'],
    'overtime_pending' => ['state' => 'error', 'text' => 'Ya tienes una solicitud pendiente para ese día.'],
    'overtime_no_shift' => ['state' => 'error', 'text' => 'No tienes un turno laborable asignado para ese día.'],
    'overtime_limit' => ['state' => 'error', 'text' => 'La autorización superaría el máximo de 12 horas extra en ese día.'],
    'overtime_invalid' => ['state' => 'error', 'text' => 'Revisa la fecha, los minutos y el motivo de la solicitud.'],
    'overtime_error' => ['state' => 'error', 'text' => 'No se pudo guardar la solicitud de horas extra. Inténtalo más tarde.'],
];
$statusParam = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$flash = $flashMessages[$statusParam] ?? null;
$users = [];
$usersError = false;
$attendanceRecords = [];
$attendanceError = false;
$employees = [];
$shiftTemplates = [];
$shiftAssignments = [];
$scheduleError = false;
$absenceRecords = [];
$absenceError = false;
$overtimeRequests = [];
$overtimeError = false;
$pendingOvertimeCount = 0;
$employeeOvertimeRequests = [];
$employeeSchedule = null;
$pendingAbsenceCount = 0;
$dashboardError = false;
$employeeCargo = null;
$workChart = ['labels' => [], 'worked' => [], 'overtime' => []];
$staffStatusChart = ['working' => 0, 'vacation' => 0, 'sick' => 0, 'off' => 0];

if ($user['rol'] === 'admin') {
    require_once __DIR__ . '/conexion.php';

    try {
        $connection = getDatabaseConnection();
        $statement = $connection->query(
            'SELECT id, nombre, cargo, dni, direccion, correo, rol
             FROM usuarios
             ORDER BY nombre ASC, id ASC'
        );
        $users = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('No se pudo cargar la lista de usuarios: ' . $exception->getMessage());
        $usersError = true;
    }

    try {
        $connection ??= getDatabaseConnection();
        $statement = $connection->query(
            'SELECT
                time_records.id,
                time_records.event_type,
                time_records.recorded_at_utc,
                time_records.latitud,
                time_records.longitud,
                time_records.horas_trabajadas_segundos,
                time_records.horas_extra_segundos,
                time_records.es_hora_extra,
                time_records.es_cierre_automatico,
                usuarios.nombre,
                usuarios.dni
             FROM time_records
             LEFT JOIN usuarios ON usuarios.id = time_records.usuario_id
             ORDER BY time_records.recorded_at_utc DESC, time_records.id DESC'
        );
        $attendanceRecords = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('No se pudo cargar el historial de fichajes: ' . $exception->getMessage());
        $attendanceError = true;
    }

    try {
        $statement = $connection->query(
            "SELECT id, nombre
             FROM usuarios
             WHERE rol = 'empleado'
             ORDER BY nombre ASC, id ASC"
        );
        $employees = $statement->fetchAll();

        $statement = $connection->query(
            'SELECT id, nombre_turno, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso, horas_descanso
             FROM plantillas_turnos
             ORDER BY nombre_turno ASC'
        );
        $shiftTemplates = $statement->fetchAll();

        $statement = $connection->query(
            'SELECT asignaciones_turno.id, asignaciones_turno.usuario_id, asignaciones_turno.turno_id,
                    plantillas_turnos.id AS plantilla_id, plantillas_turnos.horas_descanso,
                    usuarios.nombre AS empleado, turnos.nombre AS turno,
                    turnos.tipo, turnos.hora_inicio, turnos.hora_fin,
                    turnos.dias_trabajo, turnos.dias_descanso,
                    asignaciones_turno.fecha_inicio, asignaciones_turno.fecha_fin
             FROM asignaciones_turno
             JOIN usuarios ON usuarios.id = asignaciones_turno.usuario_id
             JOIN turnos ON turnos.id = asignaciones_turno.turno_id
             JOIN plantillas_turnos ON plantillas_turnos.turno_id = turnos.id
             ORDER BY asignaciones_turno.fecha_inicio DESC, asignaciones_turno.id DESC'
        );
        $shiftAssignments = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('No se pudieron cargar los horarios: ' . $exception->getMessage());
        $scheduleError = true;
    }

    try {
        $statement = $connection->query(
            'SELECT ausencias.id, ausencias.tipo, ausencias.fecha_inicio, ausencias.fecha_fin,
                    ausencias.observaciones, ausencias.estado, ausencias.creado_en_utc,
                    usuarios.nombre AS empleado, usuarios.dni
             FROM ausencias
             JOIN usuarios ON usuarios.id = ausencias.usuario_id
             ORDER BY ausencias.creado_en_utc DESC, ausencias.id DESC'
        );
        $absenceRecords = $statement->fetchAll();
        $pendingAbsenceCount = count(array_filter(
            $absenceRecords,
            static fn (array $absence): bool => $absence['estado'] === 'pendiente'
        ));
    } catch (PDOException $exception) {
        error_log('No se pudieron cargar las solicitudes de ausencia: ' . $exception->getMessage());
        $absenceError = true;
    }

    try {
        $statement = $connection->query(
            'SELECT solicitudes_horas_extra.id, solicitudes_horas_extra.usuario_id,
                    solicitudes_horas_extra.fecha, solicitudes_horas_extra.minutos,
                    solicitudes_horas_extra.motivo, solicitudes_horas_extra.estado,
                    usuarios.nombre AS empleado, usuarios.cargo
             FROM solicitudes_horas_extra
             JOIN usuarios ON usuarios.id = solicitudes_horas_extra.usuario_id
             ORDER BY (solicitudes_horas_extra.estado = \'pendiente\') DESC,
                      solicitudes_horas_extra.fecha DESC, solicitudes_horas_extra.id DESC'
        );
        $overtimeRequests = $statement->fetchAll();
        $pendingOvertimeCount = count(array_filter(
            $overtimeRequests,
            static fn (array $request): bool => $request['estado'] === 'pendiente'
        ));
    } catch (PDOException $exception) {
        error_log('No se pudieron cargar las solicitudes de horas extra: ' . $exception->getMessage());
        $overtimeError = true;
    }

    try {
        $madrid = new DateTimeZone('Europe/Madrid');
        $today = new DateTimeImmutable('today', $madrid);
        $chartStartUtc = $today->modify('-6 days')->setTimezone(new DateTimeZone('UTC'));
        $chartEndUtc = $today->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $chartQuery = $connection->prepare(
            "SELECT recorded_at_utc, horas_trabajadas_segundos, horas_extra_segundos
             FROM time_records
             WHERE event_type = 'exit'
               AND recorded_at_utc >= :start_utc
               AND recorded_at_utc < :end_utc
             ORDER BY recorded_at_utc ASC"
        );
        $chartQuery->execute([
            'start_utc' => $chartStartUtc->format('Y-m-d H:i:s.u'),
            'end_utc' => $chartEndUtc->format('Y-m-d H:i:s.u'),
        ]);
        $dailyTotals = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $date = $today->modify('-' . $offset . ' days');
            $key = $date->format('Y-m-d');
            $dailyTotals[$key] = ['worked' => 0, 'overtime' => 0];
            $workChart['labels'][] = $date->format('d/m');
        }

        foreach ($chartQuery->fetchAll() as $exitRecord) {
            $recordDate = (new DateTimeImmutable($exitRecord['recorded_at_utc'], new DateTimeZone('UTC')))
                ->setTimezone($madrid)
                ->format('Y-m-d');
            if (isset($dailyTotals[$recordDate]) && $exitRecord['horas_trabajadas_segundos'] !== null) {
                $dailyTotals[$recordDate]['worked'] += (int) $exitRecord['horas_trabajadas_segundos'];
                $dailyTotals[$recordDate]['overtime'] += (int) ($exitRecord['horas_extra_segundos'] ?? 0);
            }
        }
        foreach ($dailyTotals as $totals) {
            $workChart['worked'][] = round($totals['worked'] / 3600, 2);
            $workChart['overtime'][] = round($totals['overtime'] / 3600, 2);
        }

        $employeeRows = $connection->query(
            "SELECT id FROM usuarios WHERE rol = 'empleado'"
        )->fetchAll();
        $staffStatusChart['off'] = count($employeeRows);
        $todayStartUtc = $today->setTimezone(new DateTimeZone('UTC'));
        $todayEndUtc = $today->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $latestEventsQuery = $connection->prepare(
            'SELECT usuario_id, event_type
             FROM time_records
             WHERE usuario_id IS NOT NULL
               AND recorded_at_utc >= :start_utc
               AND recorded_at_utc < :end_utc
             ORDER BY recorded_at_utc DESC, id DESC'
        );
        $latestEventsQuery->execute([
            'start_utc' => $todayStartUtc->format('Y-m-d H:i:s.u'),
            'end_utc' => $todayEndUtc->format('Y-m-d H:i:s.u'),
        ]);
        $latestEvents = [];
        foreach ($latestEventsQuery->fetchAll() as $event) {
            $latestEvents[(int) $event['usuario_id']] ??= $event['event_type'];
        }

        $activeAbsencesQuery = $connection->prepare(
            "SELECT usuario_id, tipo
             FROM ausencias
             WHERE estado = 'aprobada'
               AND fecha_inicio <= :today_start
               AND fecha_fin >= :today_end
               AND tipo IN ('vacaciones', 'baja_medica')"
        );
        $todayString = $today->format('Y-m-d');
        $activeAbsencesQuery->execute(['today_start' => $todayString, 'today_end' => $todayString]);
        $employeesWithAbsence = [];
        foreach ($activeAbsencesQuery->fetchAll() as $absence) {
            $employeesWithAbsence[(int) $absence['usuario_id']] = $absence['tipo'];
        }

        foreach ($employeeRows as $employeeRow) {
            $employeeId = (int) $employeeRow['id'];
            if (isset($employeesWithAbsence[$employeeId])) {
                $category = $employeesWithAbsence[$employeeId] === 'baja_medica' ? 'sick' : 'vacation';
                $staffStatusChart[$category]++;
                $staffStatusChart['off']--;
            } elseif (in_array($latestEvents[$employeeId] ?? '', ['entry', 'fin_pausa'], true)) {
                $staffStatusChart['working']++;
                $staffStatusChart['off']--;
            }
        }
    } catch (PDOException $exception) {
        error_log('No se pudieron cargar los datos del dashboard: ' . $exception->getMessage());
        $dashboardError = true;
    }
} else {
    try {
        require_once __DIR__ . '/conexion.php';
        $connection = getDatabaseConnection();
        $cargoQuery = $connection->prepare('SELECT cargo FROM usuarios WHERE id = :usuario_id');
        $cargoQuery->execute(['usuario_id' => $user['id']]);
        $employeeCargo = $cargoQuery->fetchColumn() ?: null;
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
        $scheduleQuery = $connection->prepare(
            'SELECT turnos.nombre, turnos.tipo, turnos.hora_inicio, turnos.hora_fin,
                    turnos.dias_trabajo, turnos.dias_descanso, asignaciones_turno.ciclo_inicio
             FROM asignaciones_turno
             JOIN turnos ON turnos.id = asignaciones_turno.turno_id
             WHERE asignaciones_turno.usuario_id = :usuario_id
               AND asignaciones_turno.fecha_inicio <= :fecha_inicio
               AND (asignaciones_turno.fecha_fin IS NULL OR asignaciones_turno.fecha_fin >= :fecha_fin)
             ORDER BY asignaciones_turno.fecha_inicio DESC
             LIMIT 1'
        );
        $scheduleQuery->execute([
            'usuario_id' => $user['id'],
            'fecha_inicio' => $today,
            'fecha_fin' => $today,
        ]);
        $employeeSchedule = $scheduleQuery->fetch() ?: null;
        if ($employeeSchedule !== null) {
            $cycleStart = new DateTimeImmutable($employeeSchedule['ciclo_inicio']);
            $todayDate = new DateTimeImmutable($today);
            $cycleLength = (int) $employeeSchedule['dias_trabajo'] + (int) $employeeSchedule['dias_descanso'];
            $cycleDay = (int) $cycleStart->diff($todayDate)->format('%a') % $cycleLength;
            $employeeSchedule['works_today'] = $cycleDay < (int) $employeeSchedule['dias_trabajo'];
        }

        $statement = $connection->prepare(
            'SELECT id, tipo, fecha_inicio, fecha_fin, observaciones, estado, creado_en_utc
             FROM ausencias
             WHERE usuario_id = :usuario_id
             ORDER BY creado_en_utc DESC, id DESC'
        );
        $statement->execute(['usuario_id' => $user['id']]);
        $absenceRecords = $statement->fetchAll();

        $overtimeStatement = $connection->prepare(
            'SELECT id, fecha, minutos, motivo, estado
             FROM solicitudes_horas_extra
             WHERE usuario_id = :usuario_id
             ORDER BY fecha DESC, id DESC'
        );
        $overtimeStatement->execute(['usuario_id' => $user['id']]);
        $employeeOvertimeRequests = $overtimeStatement->fetchAll();
    } catch (PDOException $exception) {
        error_log('No se pudieron cargar las ausencias del empleado: ' . $exception->getMessage());
        $absenceError = true;
    }
}

$madridTimeZone = new DateTimeZone('Europe/Madrid');
$eventLabels = [
    'entry' => 'Entrada',
    'exit' => 'Salida',
    'pausa' => 'Inicio de pausa',
    'fin_pausa' => 'Fin de pausa',
];
$absenceLabels = [
    'vacaciones' => 'Vacaciones',
    'medico' => 'Cita médica',
    'asuntos_familiares' => 'Asuntos familiares',
    'fallecimiento_familiar' => 'Fallecimiento de familiar',
    'hospitalizacion_familiar' => 'Hospitalización o intervención de familiar',
    'deber_inexcusable' => 'Deber público o personal inexcusable',
    'mudanza' => 'Traslado de domicilio',
    'matrimonio' => 'Matrimonio o registro de pareja',
    'nacimiento_cuidado' => 'Nacimiento y cuidado de menor',
    'lactancia' => 'Cuidado del lactante',
    'fuerza_mayor_familiar' => 'Fuerza mayor familiar urgente',
    'permiso_retribuido' => 'Otro permiso retribuido',
    'permiso_no_retribuido' => 'Permiso no retribuido',
    'otros' => 'Otro motivo',
    'baja_medica' => 'Baja médica',
    'retraso' => 'Retraso',
    'falta' => 'Falta',
];
$absenceStatusLabels = [
    'pendiente' => 'Pendiente',
    'aprobada' => 'Aprobada',
    'rechazada' => 'Rechazada',
    'registrada' => 'Registrada por administración',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Panel de control horario">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0b1220">
    <link rel="manifest" href="manifest.json">
    <title>Control horario</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: var(--page-bg);
            --page-bg: #f1f5f9;
            --surface: #ffffff;
            --surface-muted: #eaf0f7;
            --text: #172033;
            --muted: #526078;
            --border: #dce4ef;
            --input-border: #b8c5d6;
            --shadow: 0 16px 40px rgb(15 23 42 / 8%);
            --sidebar-bg: #0b1220;
            --sidebar-text: #ffffff;
            --accent: #1d4ed8;
            --accent-hover: #1e40af;
            --accent-soft: #e7efff;
            --lavender: #7c3aed;
            --lavender-soft: #f1eafe;
            --danger: #be123c;
            --danger-hover: #9f1239;
            --success: #047857;
            --warning: #92400e;
            --chart-worked: #2563eb;
            --chart-overtime: #d97706;
            --chart-working: #059669;
            --chart-vacation: #8b5cf6;
            --chart-sick: #e11d48;
            --chart-off: #94a3b8;
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --page-bg: #111827;
            --surface: #1f2937;
            --surface-muted: #273449;
            --text: #f1f5f9;
            --muted: #a9b5c8;
            --border: #39475c;
            --input-border: #52627a;
            --shadow: 0 16px 40px rgb(0 0 0 / 24%);
            --sidebar-bg: #080e1a;
            --sidebar-text: #ffffff;
            --accent: #2563eb;
            --accent-hover: #93c5fd;
            --accent-soft: #1e3a8a;
            --lavender: #a78bfa;
            --lavender-soft: #35265d;
            --danger: #fb7185;
            --danger-hover: #fda4af;
            --success: #34d399;
            --warning: #fbbf24;
            --chart-worked: #60a5fa;
            --chart-overtime: #fbbf24;
            --chart-working: #34d399;
            --chart-vacation: #a78bfa;
            --chart-sick: #fb7185;
            --chart-off: #64748b;
            background: var(--page-bg);
            color: var(--text);
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--page-bg);
            color: var(--text);
            transition: background-color 160ms ease, color 160ms ease;
        }

        .app-shell { min-height: 100vh; }
        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 20;
            display: flex;
            width: 252px;
            flex-direction: column;
            padding: 26px 16px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
        }
        .sidebar-close {
            display: none;
            position: absolute;
            top: 20px;
            right: 12px;
            width: 40px;
            min-height: 40px;
            padding: 0;
            border: 1px solid rgb(255 255 255 / 20%);
            background: transparent;
            color: var(--sidebar-text);
            font-size: 1.4rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 8px 32px;
            color: #fff;
            font-size: 1.08rem;
            font-weight: 750;
            letter-spacing: -.02em;
        }
        .brand-mark {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border-radius: 12px;
            background: var(--accent);
            font-size: 1.2rem;
        }
        .sidebar-label {
            margin: 0 10px 10px;
            color: #cbd5e1;
            font-size: .72rem;
            font-weight: 750;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .sidebar-nav { display: grid; gap: 5px; }
        .sidebar-link, .theme-toggle {
            display: flex;
            width: 100%;
            min-height: 44px;
            align-items: center;
            gap: 11px;
            padding: 0 12px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--sidebar-text);
            font: inherit;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
        }
        .sidebar-link:hover, .theme-toggle:hover { background: rgb(255 255 255 / 9%); }
        .sidebar-link.activo,
        .sidebar-link[aria-current="page"] {
            background: var(--accent);
            color: #fff;
            font-weight: 700;
        }
        .seccion-panel:not(.activo) { display: none; }
        .seccion-panel.activo { display: block; }
        .sidebar-icon { width: 22px; text-align: center; font-size: 1.08rem; }
        .sidebar-spacer { flex: 1; }
        .sidebar-user {
            overflow: hidden;
            margin: 16px 8px 0;
            color: #cbd5e1;
            font-size: .82rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sidebar-user strong { display: block; overflow: hidden; color: #fff; text-overflow: ellipsis; }
        .sidebar-bell { margin-left: auto; }
        .notification-count {
            display: inline-grid;
            min-width: 22px;
            height: 22px;
            place-items: center;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--danger);
            color: #fff;
            font-size: .72rem;
            font-weight: 800;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            min-height: 72px;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px clamp(18px, 4vw, 44px);
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }
        .topbar-title { margin: 0; font-size: 1rem; font-weight: 700; }
        .menu-toggle {
            display: none;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            font-size: 1.2rem;
        }
        .content {
            min-width: 0;
            margin-left: 252px;
        }
        main {
            width: min(100%, 1280px);
            margin: 0 auto;
            padding: 30px clamp(18px, 4vw, 44px) 56px;
        }

        .card {
            margin-bottom: 24px;
            padding: clamp(20px, 3vw, 32px);
            border: 1px solid var(--border);
            border-radius: 20px;
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        h1 { margin: 0; font-size: clamp(1.7rem, 5vw, 2.2rem); }
        h2 { margin: 0 0 14px; font-size: 1.2rem; }
        .subtitle { margin: 8px 0 24px; color: var(--muted); }
        .user-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        .user-name { margin: 0; color: var(--muted); }
        .user-name strong { color: var(--text); }
        .logout-button {
            min-height: 40px;
            padding: 0 14px;
            background: var(--accent-soft);
            color: var(--accent-hover);
        }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .form-grid-three {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }
        .summary-month-picker { max-width: 240px; margin-bottom: 16px; }
        .summary-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 18px 0;
        }
        .summary-metrics article {
            display: grid;
            gap: 6px;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
        }
        .summary-metrics span { color: var(--muted); font-size: .9rem; }
        .summary-metrics strong { font-size: 1.35rem; }
        .daily-records-details { margin-top: 20px; }
        .daily-records-details > summary { color: var(--accent); font-weight: 700; cursor: pointer; }
        .daily-records-details[open] > summary { margin-bottom: 14px; }
        .field label { display: block; margin-bottom: 7px; font-size: .9rem; font-weight: 650; }
        .field input, .field select {
            width: 100%;
            min-height: 44px;
            padding: 9px 11px;
            border: 1px solid var(--input-border);
            border-radius: 9px;
            color: inherit;
            font: inherit;
        }
        .field input:focus-visible, .field select:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--accent) 35%, transparent);
            border-color: var(--accent);
        }
        .form-actions { margin-top: 20px; }

        button {
            min-height: 48px;
            padding: 0 20px;
            border: 0;
            border-radius: 10px;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: filter 120ms ease, transform 120ms ease;
        }

        button:hover:not(:disabled) { filter: brightness(.94); transform: translateY(-1px); }
        button:focus-visible { outline: 3px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset: 3px; }
        button:disabled { cursor: wait; opacity: .65; }
        .entry-button, .approve-button { background: var(--success); }
        .exit-button, .submit-button { background: var(--accent); }
        .reject-button { min-height: 38px; padding: 0 12px; background: var(--danger); }
        .reset-password-button { min-height: 38px; padding: 0 12px; background: var(--lavender); }
        .edit-assignment-button { min-height: 38px; padding: 0 12px; background: var(--accent); }
        .profile-button { min-height: 38px; padding: 0 12px; background: var(--success); }
        .assignment-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            white-space: nowrap;
        }
        .assignment-actions form { margin: 0; }
        .assignment-actions button {
            display: grid;
            width: 38px;
            min-height: 38px;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--muted);
            transition: background-color 140ms ease, color 140ms ease;
        }
        .assignment-actions button:hover:not(:disabled) {
            transform: none;
            filter: none;
        }
        .assignment-actions .edit-assignment-button:hover {
            background: var(--accent-soft);
            color: var(--accent-hover);
        }
        .assignment-actions .delete-assignment-button:hover {
            background: color-mix(in srgb, var(--danger) 14%, transparent);
            color: var(--danger);
        }
        .assignment-actions svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
        }
        .profile-dialog {
            width: min(900px, calc(100% - 24px));
            max-height: min(90vh, 900px);
            overflow-y: auto;
            padding: clamp(20px, 4vw, 36px);
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--surface);
            color: var(--text);
        }
        .profile-dialog::backdrop { background: rgb(45 39 34 / 62%); }
        .schedule-edit-dialog {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: grid;
            place-items: center;
            overflow-y: auto;
            padding: 20px;
            background: rgb(45 39 34 / 62%);
        }
        .schedule-edit-dialog.hidden { display: none !important; }
        .schedule-edit-dialog-content {
            width: min(900px, 100%);
            max-height: min(90vh, 900px);
            overflow-y: auto;
            padding: clamp(20px, 4vw, 36px);
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--surface);
            color: var(--text);
            box-shadow: var(--shadow);
        }
        .profile-dialog-header, .schedule-edit-dialog-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }
        .profile-dialog-header h2 { margin: 0; }
        .profile-close-button { min-height: 38px; padding: 0 12px; background: var(--muted); }
        .profile-dialog .actions { margin-top: 16px; }
        .export-button {
            display: inline-flex;
            align-items: center;
            min-height: 48px;
            margin-bottom: 16px;
            padding: 0 18px;
            border-radius: 10px;
            background: var(--success);
            color: #fff;
            font-weight: 700;
            text-decoration: none;
        }
        .export-button:hover { background: var(--accent); }
        .export-filter-form,
        .profile-export-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 14px;
            margin: 0 0 16px;
        }
        .export-filter-form .field { min-width: 180px; }
        .profile-export-form .field { min-width: 150px; flex: 1; }
        .export-filter-form .export-button,
        .profile-export-form .export-button { margin: 0; }
        .status { margin: 0 0 18px; color: var(--muted); }
        .status[data-state="error"] { color: var(--danger); }
        .status[data-state="success"] { color: var(--success); }
        .status[data-state="overtime"], .overtime-row { color: var(--warning); font-weight: 700; }
        td[data-state="overtime"] { color: var(--warning); font-weight: 700; }
        .absence-actions { display: grid; gap: 8px; min-width: 100px; }
        .absence-actions form { margin: 0; }
        .absence-actions button { min-height: 36px; padding: 0 10px; font-size: .85rem; }
        .absence-edit-details summary {
            color: var(--accent);
            font-weight: 700;
            cursor: pointer;
        }
        .absence-edit-details form { display: grid; gap: 10px; min-width: 260px; margin-top: 12px; }
        .absence-edit-details .field input,
        .absence-edit-details .field select { min-height: 38px; }
        .absence-edit-details .field label { font-size: .82rem; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 13px 12px; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-size: .82rem; font-weight: 700; }
        td { font-size: .95rem; }
        tbody tr:last-child td { border-bottom: 0; }
        .empty-row { color: var(--muted); text-align: center; }
        .role-badge {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent-hover);
            font-size: .82rem;
            font-weight: 650;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .dashboard-card {
            min-width: 0;
            margin: 0;
        }
        .dashboard-card h2 { margin-bottom: 5px; }
        .chart-description { margin: 0 0 18px; color: var(--muted); font-size: .88rem; }
        .chart-wrap { position: relative; min-height: 270px; }
        .calendar-container { min-height: 600px; }
        .calendar-container .fc { color: var(--text); }
        .calendar-container .fc .fc-button-primary {
            border-color: var(--accent);
            background: var(--accent);
        }
        .calendar-container .fc .fc-button-primary:disabled { background: var(--muted); }
        .calendar-container .fc .fc-button-primary:focus { box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--accent) 30%, transparent); }
        .chart-error { color: var(--danger); }
        :root[data-theme="dark"] input,
        :root[data-theme="dark"] select { background: var(--surface-muted); color: var(--text); }
        :root[data-theme="dark"] .logout-button { background: var(--surface-muted); color: var(--accent); }
        :root[data-theme="dark"] .role-badge { background: var(--accent-soft); color: var(--accent-hover); }
        :root[data-theme="dark"] .notification-count { color: #fff; }
        :root[data-theme="dark"] .status[data-state="error"] { color: #ff9a9a; }
        :root[data-theme="dark"] .status[data-state="success"] { color: var(--success); }
        :root[data-theme="dark"] .calendar-container .fc-theme-standard td,
        :root[data-theme="dark"] .calendar-container .fc-theme-standard th,
        :root[data-theme="dark"] .calendar-container .fc-theme-standard .fc-scrollgrid {
            border-color: var(--border);
        }
        :root[data-theme="dark"] .status[data-state="overtime"],
        :root[data-theme="dark"] td[data-state="overtime"] { color: var(--warning); }

        @media (max-width: 900px) {
            .sidebar { width: 220px; }
            .content { margin-left: 220px; }
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 680px) {
            .sidebar {
                width: min(290px, 84vw);
                transform: translateX(-105%);
                transition: transform 180ms ease;
                box-shadow: 12px 0 32px rgb(0 0 0 / 18%);
            }
            .sidebar.is-open { transform: translateX(0); }
            .sidebar-close { display: grid; place-items: center; }
            .content { margin-left: 0; }
            .topbar { min-height: 62px; padding: 10px 16px; }
            .menu-toggle { display: inline-grid; place-items: center; }
            main { padding: 20px 14px 40px; }
            .user-bar { align-items: flex-start; flex-direction: column; }
            .actions button { flex: 1 1 100%; }
            .form-grid, .form-grid-three { grid-template-columns: 1fr; }
            .summary-metrics { grid-template-columns: 1fr; }
            .dashboard-grid { gap: 14px; }
            .chart-wrap { min-height: 230px; }
            .calendar-container { min-height: 520px; }
        }
    </style>
    <?php if ($user['rol'] === 'empleado'): ?>
        <script src="app.js" defer></script>
    <?php else: ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales-all.global.min.js" defer></script>
    <script>
        (() => {
            try {
                const theme = localStorage.getItem("control-horario-theme");
                if (theme === "dark" || theme === "light") {
                    document.documentElement.dataset.theme = theme;
                }
            } catch (error) {
                document.documentElement.dataset.theme = "light";
            }
        })();
    </script>
    <script src="dashboard.js" defer></script>
    <script src="profile.js" defer></script>
    <script src="calendar.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" id="app-sidebar" aria-label="Navegación principal">
            <button class="sidebar-close" id="sidebar-close" type="button" aria-label="Cerrar menú">×</button>
            <a class="brand" href="index.php">
                <span class="brand-mark" aria-hidden="true">◷</span>
                <span>Control horario</span>
            </a>
            <p class="sidebar-label"><?= $user['rol'] === 'admin' ? 'Administración' : 'Mi espacio' ?></p>
            <nav class="sidebar-nav">
                <?php if ($user['rol'] === 'admin'): ?>
                    <a class="sidebar-link" href="#dashboard"><span class="sidebar-icon" aria-hidden="true">▦</span>Dashboard</a>
                    <a class="sidebar-link" href="#users"><span class="sidebar-icon" aria-hidden="true">♙</span>Usuarios</a>
                    <a class="sidebar-link" href="#schedules"><span class="sidebar-icon" aria-hidden="true">◷</span>Turnos</a>
                    <a class="sidebar-link" href="#shift-templates" aria-controls="shift-templates"><span class="sidebar-icon" aria-hidden="true">＋</span>Plantillas de Turnos</a>
                    <a class="sidebar-link" href="#attendance-history"><span class="sidebar-icon" aria-hidden="true">▤</span>Fichajes</a>
                    <a class="sidebar-link" href="#overtime-requests" aria-label="Horas extra<?= $pendingOvertimeCount > 0 ? ', ' . $pendingOvertimeCount . ' solicitudes pendientes' : '' ?>">
                        <span class="sidebar-icon" aria-hidden="true">＋</span>Horas extra
                        <?php if ($pendingOvertimeCount > 0): ?>
                            <span class="notification-count sidebar-bell" aria-label="<?= $pendingOvertimeCount ?> solicitudes pendientes">
                                <?= $pendingOvertimeCount > 99 ? '99+' : $pendingOvertimeCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a class="sidebar-link" href="#absence-admin" aria-label="Permisos<?= $pendingAbsenceCount > 0 ? ', ' . $pendingAbsenceCount . ' solicitudes pendientes' : '' ?>">
                        <span class="sidebar-icon" aria-hidden="true">🔔</span>Permisos
                        <?php if ($pendingAbsenceCount > 0): ?>
                            <span class="notification-count sidebar-bell" aria-label="<?= $pendingAbsenceCount ?> solicitudes pendientes">
                                <?= $pendingAbsenceCount > 99 ? '99+' : $pendingAbsenceCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <a class="sidebar-link" href="#attendance"><span class="sidebar-icon" aria-hidden="true">◷</span>Mi jornada</a>
                    <?php if ($employeeSchedule !== null): ?>
                        <a class="sidebar-link" href="#my-schedule"><span class="sidebar-icon" aria-hidden="true">▦</span>Mi turno</a>
                    <?php endif; ?>
                    <a class="sidebar-link" href="#absence-request"><span class="sidebar-icon" aria-hidden="true">♧</span>Permisos</a>
                <?php endif; ?>
                <a class="sidebar-link" href="#calendar"><span class="sidebar-icon" aria-hidden="true">▦</span>Calendario</a>
            </nav>
            <div class="sidebar-spacer"></div>
            <button class="theme-toggle" id="theme-toggle" type="button" aria-pressed="false">
                <span class="sidebar-icon" id="theme-icon" aria-hidden="true">☾</span>
                <span id="theme-label">Modo oscuro</span>
            </button>
            <p class="sidebar-user">
                <strong><?= htmlspecialchars($user['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?= $user['rol'] === 'admin' ? 'Administrador' : 'Empleado' ?>
            </p>
        </aside>
        <div class="content">
            <div class="topbar">
                <button class="menu-toggle" id="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false" aria-controls="app-sidebar">☰</button>
                <p class="topbar-title"><?= $user['rol'] === 'admin' ? 'Panel de administración' : 'Área de empleado' ?></p>
                <span class="role-badge"><?= (new DateTimeImmutable('now', $madridTimeZone))->format('d/m/Y') ?></span>
            </div>
    <main>
        <header class="card">
            <div class="user-bar">
                <p class="user-name">
                    Sesión de <strong><?= htmlspecialchars($user['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="role-badge"><?= $user['rol'] === 'admin' ? 'Administrador' : 'Empleado' ?></span>
                    <?php if ($user['rol'] === 'empleado' && $employeeCargo !== null): ?>
                        <span class="role-badge"><?= htmlspecialchars($employeeCargo, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </p>
                <form action="logout.php" method="post">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <button class="logout-button" type="submit"><span aria-hidden="true">↪</span> Cerrar sesión</button>
                </form>
            </div>
            <h1 id="page-title">Control horario</h1>
            <p class="subtitle">
                <?= $user['rol'] === 'admin' ? 'Panel de Administración' : 'Registra el inicio y el fin de tu jornada.' ?>
            </p>
        </header>

        <section class="card seccion-panel" id="calendar" aria-labelledby="calendar-title">
            <h2 id="calendar-title">Calendario compartido</h2>
            <?php if ($flash !== null && str_starts_with($statusParam, 'calendar_')): ?>
                <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                    <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
            <?php if ($user['rol'] === 'admin'): ?>
                <form action="gestionar_calendario.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-grid-three">
                        <div class="field">
                            <label for="evento-titulo">Título</label>
                            <input id="evento-titulo" name="titulo" type="text" maxlength="160" required>
                        </div>
                        <div class="field">
                            <label for="evento-fecha">Fecha</label>
                            <input id="evento-fecha" name="fecha" type="date" required>
                        </div>
                        <div class="field">
                            <label for="evento-tipo">Tipo</label>
                            <select id="evento-tipo" name="tipo" required>
                                <option value="evento">Evento</option>
                                <option value="festivo">Festivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions"><button class="submit-button" type="submit">Añadir al calendario</button></div>
                </form>
            <?php endif; ?>
            <p class="status" id="calendar-status" role="status" aria-live="polite"></p>
            <div class="calendar-container" id="shared-calendar" aria-label="Calendario de eventos y festivos"></div>
        </section>

        <?php if ($user['rol'] === 'admin'): ?>
            <section class="card seccion-panel activo" id="dashboard" aria-labelledby="dashboard-title">
                <h2 id="dashboard-title">Resumen de actividad</h2>
                <?php if ($dashboardError): ?>
                    <p class="status chart-error" role="alert">No se pudieron cargar los datos de los gráficos.</p>
                <?php endif; ?>
                <p class="status chart-error" id="dashboard-chart-error" role="alert" hidden></p>
                <div class="dashboard-grid">
                    <article class="card dashboard-card" aria-labelledby="work-chart-title">
                        <h2 id="work-chart-title">Horas trabajadas y extra</h2>
                        <p class="chart-description">Total diario de los últimos siete días.</p>
                        <div class="chart-wrap">
                            <canvas id="work-hours-chart" role="img" aria-label="Gráfico de barras con horas trabajadas y horas extra por día"></canvas>
                        </div>
                    </article>
                    <article class="card dashboard-card" aria-labelledby="staff-chart-title">
                        <h2 id="staff-chart-title">Estado del personal</h2>
                        <p class="chart-description">Estado estimado según el último fichaje y los permisos aprobados de hoy.</p>
                        <div class="chart-wrap">
                            <canvas id="staff-status-chart" role="img" aria-label="Gráfico circular con el estado del personal"></canvas>
                        </div>
                    </article>
                </div>
                <p class="chart-description">“Fuera de jornada / en pausa” incluye a empleados que aún no han fichado hoy, ya finalizaron su jornada o están en pausa.</p>
            </section>

            <div class="seccion-panel" id="users" aria-label="Gestión de usuarios">
            <section class="card" id="create-user" aria-labelledby="create-user-title">
                <h2 id="create-user-title">Dar de alta un usuario</h2>
                <?php if ($flash !== null): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <form action="crear_usuario.php" method="post">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <div class="form-grid">
                        <div class="field">
                            <label for="nombre">Nombre completo</label>
                            <input id="nombre" name="nombre" type="text" maxlength="150" autocomplete="name" required>
                        </div>
                        <div class="field">
                            <label for="cargo">Cargo en la empresa</label>
                            <input id="cargo" name="cargo" type="text" maxlength="100" autocomplete="organization-title">
                        </div>
                        <div class="field">
                            <label for="dni">DNI</label>
                            <input id="dni" name="dni" type="text" maxlength="20" autocomplete="off">
                        </div>
                        <div class="field">
                            <label for="direccion">Dirección</label>
                            <input id="direccion" name="direccion" type="text" maxlength="255" autocomplete="street-address">
                        </div>
                        <div class="field">
                            <label for="correo">Correo electrónico</label>
                            <input id="correo" name="correo" type="email" maxlength="254" autocomplete="email" required>
                        </div>
                        <div class="field">
                            <label for="password">Contraseña (mínimo 7 caracteres)</label>
                            <input id="password" name="password" type="password" minlength="7" autocomplete="new-password" required>
                        </div>
                        <div class="field">
                            <label for="rol">Rol</label>
                            <select id="rol" name="rol" required>
                                <option value="empleado" selected>Empleado</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="submit-button" type="submit"><span aria-hidden="true">＋</span> Crear usuario</button>
                    </div>
                </form>
            </section>

            <section class="card" id="users-list" aria-labelledby="users-title">
                <h2 id="users-title">Usuarios registrados</h2>
                <?php if ($usersError): ?>
                    <p class="status" data-state="error" role="alert">No se pudo cargar la lista de usuarios.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Nombre</th>
                                    <th scope="col">Cargo</th>
                                    <th scope="col">DNI</th>
                                    <th scope="col">Dirección</th>
                                    <th scope="col">Correo</th>
                                    <th scope="col">Rol</th>
                                    <th scope="col">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users === []): ?>
                                    <tr><td class="empty-row" colspan="7">No hay usuarios registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $registeredUser): ?>
                                        <tr data-user-row="<?= (int) $registeredUser['id'] ?>">
                                            <td data-profile-name><?= htmlspecialchars($registeredUser['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td data-profile-cargo><?= htmlspecialchars($registeredUser['cargo'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td data-profile-dni><?= htmlspecialchars($registeredUser['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td data-profile-address><?= htmlspecialchars($registeredUser['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td data-profile-email><?= htmlspecialchars($registeredUser['correo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= $registeredUser['rol'] === 'admin' ? 'Administrador' : 'Empleado' ?></td>
                                            <td>
                                                <?php if ($registeredUser['rol'] === 'empleado'): ?>
                                                    <button
                                                        class="profile-button"
                                                        type="button"
                                                        data-view-profile="<?= (int) $registeredUser['id'] ?>"
                                                    >Ver Perfil</button>
                                                <?php endif; ?>
                                                <button
                                                    class="reset-password-button"
                                                    type="button"
                                                    data-reset-password="<?= (int) $registeredUser['id'] ?>"
                                                    data-user-name="<?= htmlspecialchars($registeredUser['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                                >Restablecer Clave</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            <dialog class="profile-dialog" id="profile-dialog" aria-labelledby="profile-title">
                <div class="profile-dialog-header">
                    <h2 id="profile-title">Perfil del trabajador</h2>
                    <button class="profile-close-button" type="button" data-close-profile>Cerrar</button>
                </div>
                <p class="status" id="profile-status" role="status" aria-live="polite"></p>
                <form id="profile-form">
                    <input type="hidden" name="usuario_id" id="profile-user-id">
                    <div class="form-grid">
                        <div class="field">
                            <label for="profile-name">Nombre</label>
                            <input id="profile-name" name="nombre" maxlength="150" required>
                        </div>
                        <div class="field">
                            <label for="profile-cargo">Cargo en la empresa</label>
                            <input id="profile-cargo" name="cargo" maxlength="100">
                        </div>
                        <div class="field">
                            <label for="profile-dni">DNI</label>
                            <input id="profile-dni" name="dni" maxlength="20">
                        </div>
                        <div class="field">
                            <label for="profile-address">Dirección</label>
                            <input id="profile-address" name="direccion" maxlength="255">
                        </div>
                        <div class="field">
                            <label for="profile-email">Correo electrónico</label>
                            <input id="profile-email" name="correo" type="email" maxlength="254" required>
                        </div>
                    </div>
                    <div class="actions">
                        <button class="submit-button" type="submit" id="profile-save">Guardar cambios</button>
                    </div>
                </form>
                <section aria-labelledby="profile-attendance-title">
                    <h2 id="profile-attendance-title">Historial de fichajes</h2>
                    <form action="exportar.php" method="get" class="profile-export-form">
                        <input id="profile-export-employee" type="hidden" name="usuario_id">
                        <div class="field">
                            <label for="profile-export-start">Desde</label>
                            <input id="profile-export-start" name="desde" type="date" required>
                        </div>
                        <div class="field">
                            <label for="profile-export-end">Hasta</label>
                            <input id="profile-export-end" name="hasta" type="date" required>
                        </div>
                        <button class="export-button" type="submit">Descargar fichajes del trabajador</button>
                    </form>
                    <div class="field">
                        <label for="profile-month">Mes</label>
                        <input id="profile-month" type="month" required>
                    </div>
                    <p class="status" id="profile-history-status" role="status" aria-live="polite"></p>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Fecha</th><th>Tipo</th><th>Hora</th><th>Horas trabajadas</th><th>Horas extra</th></tr>
                            </thead>
                            <tbody id="profile-attendance-body">
                                <tr><td class="empty-row" colspan="5">Selecciona un perfil para cargar fichajes.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </dialog>
            </div>

            <section class="card seccion-panel" id="shift-templates" aria-labelledby="templates-title">
                <h2 id="templates-title">Plantillas de Turnos</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'template_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <form action="gestionar_plantillas_turnos.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-grid-three">
                        <div class="field">
                            <label for="plantilla-nombre">Nombre del turno</label>
                            <input id="plantilla-nombre" name="nombre_turno" type="text" maxlength="100" required>
                        </div>
                        <div class="field">
                            <label for="plantilla-tipo">Tipo</label>
                            <select id="plantilla-tipo" name="tipo" required>
                                <option value="fijo">Fijo</option>
                                <option value="rotativo">Rotativo</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="plantilla-inicio">Hora de inicio</label>
                            <input id="plantilla-inicio" name="hora_inicio" type="time" required>
                        </div>
                        <div class="field">
                            <label for="plantilla-fin">Hora de fin</label>
                            <input id="plantilla-fin" name="hora_fin" type="time" required>
                        </div>
                        <div class="field">
                            <label for="plantilla-dias-trabajo">Días de trabajo consecutivos</label>
                            <input id="plantilla-dias-trabajo" name="dias_trabajo" type="number" min="1" max="31" required>
                        </div>
                        <div class="field">
                            <label for="plantilla-dias-descanso">Días de descanso consecutivos</label>
                            <input id="plantilla-dias-descanso" name="dias_descanso" type="number" min="1" max="31" required>
                        </div>
                        <div class="field">
                            <label for="plantilla-horas-descanso">Horas de pausa</label>
                            <input id="plantilla-horas-descanso" name="horas_descanso" type="number" min="0" max="24" step="0.25" value="0" required>
                        </div>
                    </div>
                    <div class="form-actions"><button class="submit-button" type="submit">Crear plantilla</button></div>
                </form>
                <?php if ($scheduleError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar las plantillas.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nombre</th><th>Tipo</th><th>Horario</th><th>Ciclo trabajo/descanso</th><th>Pausa</th></tr></thead>
                            <tbody>
                                <?php if ($shiftTemplates === []): ?>
                                    <tr><td class="empty-row" colspan="5">Todavía no hay plantillas de turno.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($shiftTemplates as $template): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($template['nombre_turno'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= $template['tipo'] === 'fijo' ? 'Fijo' : 'Rotativo' ?></td>
                                            <td><?= htmlspecialchars(substr($template['hora_inicio'], 0, 5) . '–' . substr($template['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int) $template['dias_trabajo'] ?> trabajo / <?= (int) $template['dias_descanso'] ?> descanso</td>
                                            <td><?= htmlspecialchars(rtrim(rtrim(number_format((float) $template['horas_descanso'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?> h</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card seccion-panel" id="schedules" aria-labelledby="schedule-title">
                <h2 id="schedule-title">Gestión de horarios y turnos</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'shift_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <?php if ($scheduleError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar los horarios.</p>
                <?php elseif ($employees === [] || $shiftTemplates === []): ?>
                    <p class="status">Para asignar turnos debe haber empleados y plantillas de turno activas.</p>
                <?php else: ?>
                    <form action="gestionar_turnos.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-grid-three">
                            <div class="field">
                                <label for="turno-usuario">Empleado</label>
                                <select id="turno-usuario" name="usuario_id" required>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="turno-id">Turno</label>
                                <select id="turno-id" name="turno_id" required>
                                    <?php foreach ($shiftTemplates as $template): ?>
                                        <option
                                            value="<?= (int) $template['id'] ?>"
                                            data-break-hours="<?= htmlspecialchars(number_format((float) $template['horas_descanso'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                            <?= htmlspecialchars($template['nombre_turno'] . ' (' . substr($template['hora_inicio'], 0, 5) . '–' . substr($template['hora_fin'], 0, 5) . ')', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="turno-inicio">Fecha de inicio</label>
                                <input id="turno-inicio" name="fecha_inicio" type="date" required>
                            </div>
                            <div class="field">
                                <label for="turno-fin">Fecha de fin (opcional)</label>
                                <input id="turno-fin" name="fecha_fin" type="date">
                            </div>
                        </div>
                        <div class="form-actions"><button class="submit-button" type="submit"><span aria-hidden="true">◷</span> Asignar turno</button></div>
                    </form>
                <?php endif; ?>

                <h2>Asignaciones de turnos</h2>
                <p class="status" id="schedule-edit-status" role="status" aria-live="polite" hidden></p>
                <?php if (!$scheduleError): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Empleado</th><th>Turno</th><th>Tipo</th><th>Horario</th><th>Ciclo trabajo/descanso</th><th>Vigencia</th><th>Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($shiftAssignments === []): ?>
                                    <tr><td class="empty-row" colspan="7">Todavía no hay turnos asignados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($shiftAssignments as $assignment): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($assignment['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($assignment['turno'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= $assignment['tipo'] === 'fijo' ? 'Fijo' : 'Rotativo' ?></td>
                                            <td><?= htmlspecialchars(substr($assignment['hora_inicio'], 0, 5) . '–' . substr($assignment['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?= (int) $assignment['dias_trabajo'] ?> trabajo / <?= (int) $assignment['dias_descanso'] ?> descanso
                                                · <?= htmlspecialchars(rtrim(rtrim(number_format((float) $assignment['horas_descanso'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?> h de pausa
                                            </td>
                                            <td><?= htmlspecialchars($assignment['fecha_inicio'] . ' – ' . ($assignment['fecha_fin'] ?? 'sin fecha de fin'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <div class="assignment-actions">
                                                <button
                                                    class="edit-assignment-button btn-editar"
                                                    type="button"
                                                    aria-label="Editar asignación de <?= htmlspecialchars($assignment['empleado'], ENT_QUOTES, 'UTF-8') ?>"
                                                    title="Editar"
                                                    data-edit-assignment="<?= (int) $assignment['id'] ?>"
                                                    data-employee-id="<?= (int) $assignment['usuario_id'] ?>"
                                                    data-template-id="<?= (int) $assignment['plantilla_id'] ?>"
                                                    data-start-date="<?= htmlspecialchars($assignment['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-end-date="<?= htmlspecialchars($assignment['fecha_fin'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-break-hours="<?= htmlspecialchars(number_format((float) $assignment['horas_descanso'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                                >
                                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>
                                                    </svg>
                                                </button>
                                                <form action="gestionar_turnos.php" method="post" onsubmit="return confirm('¿Eliminar esta asignación de turno? El usuario y sus fichajes no se modificarán.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="asignacion_id" value="<?= (int) $assignment['id'] ?>">
                                                    <button
                                                        class="delete-assignment-button"
                                                        type="submit"
                                                        aria-label="Eliminar asignación de <?= htmlspecialchars($assignment['empleado'], ENT_QUOTES, 'UTF-8') ?>"
                                                        title="Eliminar"
                                                    >
                                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                            <path d="M3 6h18"></path>
                                                            <path d="M8 6V4h8v2"></path>
                                                            <path d="m19 6-1 14H6L5 6"></path>
                                                            <path d="M10 11v5M14 11v5"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="schedule-edit-dialog hidden" id="schedule-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="schedule-edit-title" aria-hidden="true">
                    <div class="schedule-edit-dialog-content">
                    <div class="schedule-edit-dialog-header">
                        <h2 id="schedule-edit-title">Editar asignación de turno</h2>
                        <button class="profile-close-button" type="button" data-close-schedule-edit>Cerrar</button>
                    </div>
                    <form action="gestionar_turnos.php" method="post" id="schedule-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="asignacion_id" id="edit-assignment-id">
                        <div class="form-grid">
                            <div class="field">
                                <label for="edit-assignment-employee">Empleado</label>
                                <select id="edit-assignment-employee" name="usuario_id" required>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="edit-assignment-template">Turno</label>
                                <select id="edit-assignment-template" name="turno_id" required>
                                    <?php foreach ($shiftTemplates as $template): ?>
                                        <option
                                            value="<?= (int) $template['id'] ?>"
                                            data-break-hours="<?= htmlspecialchars(number_format((float) $template['horas_descanso'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                            <?= htmlspecialchars($template['nombre_turno'] . ' (' . substr($template['hora_inicio'], 0, 5) . '–' . substr($template['hora_fin'], 0, 5) . ')', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="edit-assignment-start">Fecha de inicio</label>
                                <input id="edit-assignment-start" name="fecha_inicio" type="date" required>
                            </div>
                            <div class="field">
                                <label for="edit-assignment-end">Fecha de fin (opcional)</label>
                                <input id="edit-assignment-end" name="fecha_fin" type="date">
                            </div>
                            <div class="field">
                                <label for="edit-assignment-break">Horas de pausa del turno</label>
                                <input id="edit-assignment-break" name="horas_descanso" type="number" min="0" max="24" step="0.25" required>
                            </div>
                        </div>
                        <p class="status">Las horas de pausa pertenecen a la plantilla seleccionada, por lo que también se actualizan para otras asignaciones de ese mismo turno.</p>
                        <div class="form-actions">
                            <button class="submit-button" type="submit">Guardar cambios</button>
                        </div>
                    </form>
                    </div>
                </div>
            </section>

            <section class="card seccion-panel" id="attendance-history" aria-labelledby="attendance-history-title">
                <h2 id="attendance-history-title">Historial de fichajes de la empresa</h2>
                <form action="exportar.php" method="get" class="export-filter-form">
                    <div class="field">
                        <label for="export-employee">Trabajador</label>
                        <select id="export-employee" name="usuario_id">
                            <option value="">Todos los trabajadores</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="export-start">Desde</label>
                        <input id="export-start" name="desde" type="date" required>
                    </div>
                    <div class="field">
                        <label for="export-end">Hasta</label>
                        <input id="export-end" name="hasta" type="date" required>
                    </div>
                    <button class="export-button" type="submit"><span aria-hidden="true">⇩</span> Descargar Excel (CSV)</button>
                </form>
                <p class="status">Selecciona un trabajador o todos, y cualquier periodo (mes, varios meses o años). El archivo incluye entradas, pausas, fin de pausa y salidas.</p>
                <?php if ($attendanceError): ?>
                    <p class="status" data-state="error" role="alert">No se pudo cargar el historial de fichajes.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Nombre</th>
                                    <th scope="col">DNI</th>
                                    <th scope="col">Fecha</th>
                                    <th scope="col">Hora</th>
                                    <th scope="col">Tipo de fichaje</th>
                                    <th scope="col">Latitud</th>
                                    <th scope="col">Longitud</th>
                                    <th scope="col">Horas trabajadas</th>
                                    <th scope="col">Horas extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($attendanceRecords === []): ?>
                                    <tr><td class="empty-row" colspan="9">Todavía no hay fichajes registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($attendanceRecords as $record): ?>
                                        <?php
                                        $recordedAt = new DateTimeImmutable($record['recorded_at_utc'], new DateTimeZone('UTC'));
                                        $recordedAt = $recordedAt->setTimezone($madridTimeZone);
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($record['nombre'] ?? 'Sin usuario asociado', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($record['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($recordedAt->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($recordedAt->format('H:i:s.u'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(
                                                (bool) $record['es_cierre_automatico']
                                                    ? 'Salida (cierre automático)'
                                                    : ($eventLabels[$record['event_type']] ?? $record['event_type']),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?></td>
                                            <td><?= htmlspecialchars($record['latitud'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($record['longitud'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td>
                                                <?php if ($record['horas_trabajadas_segundos'] !== null): ?>
                                                    <?= sprintf('%02d:%02d', intdiv((int) $record['horas_trabajadas_segundos'], 3600), intdiv((int) $record['horas_trabajadas_segundos'] % 3600, 60)) ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td data-state="<?= (bool) $record['es_hora_extra'] ? 'overtime' : '' ?>">
                                                <?php if ($record['horas_extra_segundos'] !== null): ?>
                                                    <?= sprintf('%02d:%02d', intdiv((int) $record['horas_extra_segundos'], 3600), intdiv((int) $record['horas_extra_segundos'] % 3600, 60)) ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card seccion-panel" id="overtime-requests" aria-labelledby="overtime-requests-title">
                <h2 id="overtime-requests-title">Solicitudes de horas extra</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'overtime_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <?php if ($overtimeError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar las solicitudes de horas extra.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Trabajador</th><th>Cargo</th><th>Fecha</th><th>Minutos</th><th>Motivo</th><th>Estado</th><th>Acción</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($overtimeRequests === []): ?>
                                    <tr><td class="empty-row" colspan="7">No hay solicitudes de horas extra.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($overtimeRequests as $request): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($request['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($request['cargo'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($request['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int) $request['minutos'] ?></td>
                                            <td><?= htmlspecialchars($request['motivo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(ucfirst($request['estado']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?php if ($request['estado'] === 'pendiente'): ?>
                                                    <div class="absence-actions">
                                                        <form action="gestionar_horas_extra.php" method="post">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="review">
                                                            <input type="hidden" name="solicitud_id" value="<?= (int) $request['id'] ?>">
                                                            <input type="hidden" name="decision" value="aprobada">
                                                            <button class="approve-button" type="submit">Aprobar</button>
                                                        </form>
                                                        <form action="gestionar_horas_extra.php" method="post">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="review">
                                                            <input type="hidden" name="solicitud_id" value="<?= (int) $request['id'] ?>">
                                                            <input type="hidden" name="decision" value="rechazada">
                                                            <button class="reject-button" type="submit">Rechazar</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card seccion-panel" id="absence-admin" aria-labelledby="absence-admin-title">
                <h2 id="absence-admin-title">Ausencias e incidencias</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'absence_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <h2>Registrar baja médica, retraso o falta</h2>
                <?php if ($employees !== []): ?>
                    <form action="gestionar_ausencias.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="manual">
                        <div class="form-grid-three">
                            <div class="field">
                                <label for="incidencia-empleado">Empleado</label>
                                <select id="incidencia-empleado" name="usuario_id" required>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="incidencia-tipo">Tipo</label>
                                <select id="incidencia-tipo" name="tipo" required>
                                    <option value="baja_medica">Baja médica</option>
                                    <option value="retraso">Retraso</option>
                                    <option value="falta">Falta</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="incidencia-fecha">Fecha</label>
                                <input id="incidencia-fecha" name="fecha_inicio" type="date" required>
                            </div>
                            <div class="field">
                                <label for="incidencia-fecha-fin">Fecha final (baja)</label>
                                <input id="incidencia-fecha-fin" name="fecha_fin" type="date">
                            </div>
                            <div class="field">
                                <label for="incidencia-nota">Observaciones</label>
                                <input id="incidencia-nota" name="observaciones" type="text" maxlength="1000">
                            </div>
                        </div>
                        <div class="form-actions"><button class="submit-button" type="submit"><span aria-hidden="true">＋</span> Registrar incidencia</button></div>
                    </form>
                <?php else: ?>
                    <p class="status">No hay empleados a quienes asignar una incidencia.</p>
                <?php endif; ?>

                <h2>Solicitudes y registros</h2>
                <?php if ($absenceError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar las ausencias.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Empleado</th><th>Tipo</th><th>Fechas</th><th>Observaciones</th><th>Estado</th><th>Acción</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($absenceRecords === []): ?>
                                    <tr><td class="empty-row" colspan="6">No hay solicitudes ni incidencias.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($absenceRecords as $absence): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($absence['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absenceLabels[$absence['tipo']] ?? $absence['tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absence['fecha_inicio'] . ' – ' . $absence['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absence['observaciones'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($absenceStatusLabels[$absence['estado']] ?? $absence['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?php if ($absence['estado'] === 'pendiente'): ?>
                                                    <form action="gestionar_ausencias.php" method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="review">
                                                        <input type="hidden" name="ausencia_id" value="<?= (int) $absence['id'] ?>">
                                                        <button class="approve-button" name="decision" value="aprobada" type="submit"><span aria-hidden="true">✓</span> Aprobar</button>
                                                        <button class="reject-button" name="decision" value="rechazada" type="submit"><span aria-hidden="true">×</span> Rechazar</button>
                                                    </form>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <?php if ($employeeSchedule !== null): ?>
                <section class="card seccion-panel" id="my-schedule" aria-labelledby="my-schedule-title">
                    <h2 id="my-schedule-title">Mi turno de hoy</h2>
                    <p>
                        <strong><?= htmlspecialchars($employeeSchedule['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                        (<?= $employeeSchedule['tipo'] === 'fijo' ? 'fijo' : 'rotativo' ?>),
                        <?= htmlspecialchars(substr($employeeSchedule['hora_inicio'], 0, 5) . '–' . substr($employeeSchedule['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?>.
                        Hoy <?= $employeeSchedule['works_today'] ? 'es día de trabajo' : 'es día de descanso' ?>.
                    </p>
                </section>
            <?php endif; ?>
            <section class="card seccion-panel activo" id="attendance" aria-labelledby="attendance-title">
                <h2 id="attendance-title">Mi jornada de hoy</h2>
                <?php if ($flash !== null): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <div class="actions" aria-label="Acciones de fichaje">
                    <button class="entry-button" id="entry-button" type="button"><span aria-hidden="true">↪</span> Fichar Entrada</button>
                    <button class="entry-button" id="pause-button" type="button"><span aria-hidden="true">Ⅱ</span> Iniciar Pausa</button>
                    <button class="exit-button" id="resume-button" type="button"><span aria-hidden="true">▶</span> Finalizar Pausa</button>
                    <button class="exit-button" id="exit-button" type="button"><span aria-hidden="true">↗</span> Fichar Salida</button>
                </div>
                <p class="status" id="status" role="status" aria-live="polite">
                    Para fichar es obligatorio permitir el acceso a la ubicación del dispositivo.
                </p>
                <p class="status" id="daily-summary" role="status" aria-live="polite">
                    Las horas trabajadas y las posibles horas extra se calcularán al fichar la salida.
                </p>

                <h2>Solicitar horas extra</h2>
                <p class="status">Indica el día laborable, los minutos adicionales y el motivo. No podrás fichar la salida después de tu turno hasta que administración lo autorice.</p>
                <form action="gestionar_horas_extra.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="request">
                    <div class="form-grid-three">
                        <div class="field">
                            <label for="overtime-date">Fecha del turno</label>
                            <input id="overtime-date" name="fecha" type="date" min="<?= (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Madrid')))->format('Y-m-d') ?>" value="<?= (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))->format('Y-m-d') ?>" required>
                        </div>
                        <div class="field">
                            <label for="overtime-minutes">Minutos extra solicitados (máximo 720)</label>
                            <input id="overtime-minutes" name="minutos" type="number" min="1" max="720" step="1" required>
                        </div>
                        <div class="field">
                            <label for="overtime-reason">Motivo</label>
                            <input id="overtime-reason" name="motivo" type="text" maxlength="1000" required>
                        </div>
                    </div>
                    <div class="form-actions"><button class="submit-button" type="submit">Enviar solicitud de horas extra</button></div>
                </form>
                <h2>Mis solicitudes de horas extra</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Fecha</th><th>Minutos</th><th>Motivo</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php if ($employeeOvertimeRequests === []): ?>
                                <tr><td class="empty-row" colspan="4">Todavía no has solicitado horas extra.</td></tr>
                            <?php else: ?>
                                <?php foreach ($employeeOvertimeRequests as $request): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($request['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $request['minutos'] ?></td>
                                        <td><?= htmlspecialchars($request['motivo'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(ucfirst($request['estado']), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h2>Resumen de horas trabajadas</h2>
                <p class="status">Consulta tus totales mensuales y semanales. El detalle de pausas se muestra únicamente para el día y se puede descargar por separado.</p>
                <p class="chart-description">Una jornada en curso se suma al resumen cuando registras la salida o cuando el sistema la cierra automáticamente.</p>
                <div class="field summary-month-picker">
                    <label for="work-summary-month">Mes</label>
                    <input id="work-summary-month" type="month" value="<?= (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))->format('Y-m') ?>">
                </div>
                <p class="status" id="work-summary-status" role="status" aria-live="polite">Cargando resumen…</p>
                <div class="summary-metrics" aria-live="polite">
                    <article><span>Horas del mes</span><strong id="monthly-worked-hours">—</strong></article>
                    <article><span>Días trabajados</span><strong id="monthly-worked-days">—</strong></article>
                    <article><span>Horas extra</span><strong id="monthly-overtime-hours">—</strong></article>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th scope="col">Semana</th><th scope="col">Días trabajados</th><th scope="col">Horas</th></tr></thead>
                        <tbody id="work-summary-weeks">
                            <tr><td class="empty-row" colspan="3">Cargando resumen…</td></tr>
                        </tbody>
                    </table>
                </div>

                <form action="exportar.php" method="get" id="employee-daily-export">
                    <input type="hidden" name="hasta" id="employee-daily-export-end">
                    <div class="form-grid">
                        <div class="field">
                            <label for="employee-daily-export-date">Descargar detalle de un día</label>
                            <input id="employee-daily-export-date" name="desde" type="date" required>
                        </div>
                        <div class="form-actions"><button class="submit-button" type="submit">Descargar Excel compatible</button></div>
                    </div>
                </form>
                <details class="daily-records-details">
                    <summary>Ver fichajes y pausas de hoy</summary>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th scope="col">Hora</th><th scope="col">Tipo de fichaje</th><th scope="col">Jornada</th></tr>
                            </thead>
                            <tbody id="records-body">
                                <tr><td class="empty-row" colspan="3">Cargando registros…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            </section>

            <section class="card seccion-panel" id="absence-request" aria-labelledby="absence-request-title">
                <h2 id="absence-request-title">Solicitar días libres</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'absence_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <form action="gestionar_ausencias.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="request">
                    <div class="form-grid-three">
                        <div class="field">
                            <label for="solicitud-tipo">Motivo</label>
                            <select id="solicitud-tipo" name="tipo" required>
                                <option value="vacaciones">Vacaciones</option>
                                <option value="medico">Cita médica</option>
                                <option value="asuntos_familiares">Asuntos familiares</option>
                                <option value="fallecimiento_familiar">Fallecimiento de familiar</option>
                                <option value="hospitalizacion_familiar">Hospitalización o intervención de familiar</option>
                                <option value="deber_inexcusable">Deber público o personal inexcusable</option>
                                <option value="mudanza">Traslado de domicilio</option>
                                <option value="matrimonio">Matrimonio o registro de pareja</option>
                                <option value="nacimiento_cuidado">Nacimiento y cuidado de menor</option>
                                <option value="lactancia">Cuidado del lactante</option>
                                <option value="fuerza_mayor_familiar">Fuerza mayor familiar urgente</option>
                                <option value="permiso_retribuido">Otro permiso retribuido</option>
                                <option value="permiso_no_retribuido">Permiso no retribuido</option>
                                <option value="otros">Otro motivo</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="solicitud-inicio">Desde</label>
                            <input id="solicitud-inicio" name="fecha_inicio" type="date" required>
                        </div>
                        <div class="field">
                            <label for="solicitud-fin">Hasta</label>
                            <input id="solicitud-fin" name="fecha_fin" type="date" required>
                        </div>
                        <div class="field">
                            <label for="solicitud-observaciones">Observaciones (opcional)</label>
                            <input id="solicitud-observaciones" name="observaciones" type="text" maxlength="1000">
                        </div>
                    </div>
                    <p class="status">
                        Catálogo orientativo: los requisitos, duración, documentación y aprobación dependen de la normativa,
                        del convenio y de la política de la empresa. No incluyas diagnósticos ni detalles médicos en las observaciones.
                    </p>
                    <div class="form-actions"><button class="submit-button" type="submit"><span aria-hidden="true">▦</span> Enviar solicitud</button></div>
                </form>

                <h2>Mis solicitudes</h2>
                <?php if ($absenceError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar tus solicitudes.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tipo</th><th>Fechas</th><th>Observaciones</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody>
                                <?php if ($absenceRecords === []): ?>
                                    <tr><td class="empty-row" colspan="5">No has enviado solicitudes ni tienes incidencias registradas.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($absenceRecords as $absence): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($absenceLabels[$absence['tipo']] ?? $absence['tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absence['fecha_inicio'] . ' – ' . $absence['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absence['observaciones'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($absenceStatusLabels[$absence['estado']] ?? $absence['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?php if ($absence['estado'] === 'pendiente'): ?>
                                                    <div class="absence-actions">
                                                        <details class="absence-edit-details">
                                                            <summary>Modificar</summary>
                                                            <form action="gestionar_ausencias.php" method="post">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="update_request">
                                                            <input type="hidden" name="ausencia_id" value="<?= (int) $absence['id'] ?>">
                                                            <div class="field">
                                                                <label for="solicitud-tipo-<?= (int) $absence['id'] ?>">Motivo</label>
                                                                <select id="solicitud-tipo-<?= (int) $absence['id'] ?>" name="tipo" required>
                                                                    <?php foreach ($absenceLabels as $type => $label): ?>
                                                                        <?php if (in_array($type, ['baja_medica', 'retraso', 'falta'], true)) continue; ?>
                                                                        <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"<?= $absence['tipo'] === $type ? ' selected' : '' ?>>
                                                                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="field">
                                                                <label for="solicitud-inicio-<?= (int) $absence['id'] ?>">Desde</label>
                                                                <input id="solicitud-inicio-<?= (int) $absence['id'] ?>" name="fecha_inicio" type="date" value="<?= htmlspecialchars($absence['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                            </div>
                                                            <div class="field">
                                                                <label for="solicitud-fin-<?= (int) $absence['id'] ?>">Hasta</label>
                                                                <input id="solicitud-fin-<?= (int) $absence['id'] ?>" name="fecha_fin" type="date" value="<?= htmlspecialchars($absence['fecha_fin'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                            </div>
                                                            <div class="field">
                                                                <label for="solicitud-observaciones-<?= (int) $absence['id'] ?>">Observaciones</label>
                                                                <input id="solicitud-observaciones-<?= (int) $absence['id'] ?>" name="observaciones" type="text" maxlength="1000" value="<?= htmlspecialchars($absence['observaciones'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                            </div>
                                                            <button class="submit-button" type="submit">Guardar cambios</button>
                                                            </form>
                                                        </details>
                                                        <form action="gestionar_ausencias.php" method="post" onsubmit="return confirm('¿Eliminar esta solicitud pendiente?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="delete_request">
                                                            <input type="hidden" name="ausencia_id" value="<?= (int) $absence['id'] ?>">
                                                            <button class="reject-button" type="submit">Eliminar</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
        </div>
        <script id="dashboard-data" type="application/json"><?=
            json_encode(
                [
                    'work' => $workChart,
                    'staff' => $staffStatusChart,
                    'error' => $dashboardError,
                ],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            )
        ?></script>
    </div>
</body>
</html>
