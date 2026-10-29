<?php
declare(strict_types=1);

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
    'shift_invalid' => ['state' => 'error', 'text' => 'Los datos del turno no son válidos o se solapan con otra asignación.'],
    'shift_error' => ['state' => 'error', 'text' => 'No se pudo asignar el turno. Inténtalo más tarde.'],
    'absence_requested' => ['state' => 'success', 'text' => 'Solicitud enviada para revisión.'],
    'absence_reviewed' => ['state' => 'success', 'text' => 'Solicitud actualizada.'],
    'absence_recorded' => ['state' => 'success', 'text' => 'Incidencia registrada.'],
    'absence_invalid' => ['state' => 'error', 'text' => 'Los datos de la ausencia no son válidos.'],
    'absence_error' => ['state' => 'error', 'text' => 'No se pudo guardar la ausencia. Inténtalo más tarde.'],
];
$statusParam = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$flash = $flashMessages[$statusParam] ?? null;
$users = [];
$usersError = false;
$attendanceRecords = [];
$attendanceError = false;
$employees = [];
$shifts = [];
$shiftAssignments = [];
$scheduleError = false;
$absenceRecords = [];
$absenceError = false;
$employeeSchedule = null;
$pendingAbsenceCount = 0;
$dashboardError = false;
$workChart = ['labels' => [], 'worked' => [], 'overtime' => []];
$staffStatusChart = ['working' => 0, 'vacation' => 0, 'sick' => 0, 'off' => 0];

if ($user['rol'] === 'admin') {
    require_once __DIR__ . '/conexion.php';

    try {
        $connection = getDatabaseConnection();
        $statement = $connection->query(
            'SELECT id, nombre, dni, direccion, correo, rol
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
            'SELECT id, nombre, tipo, hora_inicio, hora_fin, dias_trabajo, dias_descanso
             FROM turnos
             WHERE activo = TRUE
             ORDER BY nombre ASC'
        );
        $shifts = $statement->fetchAll();

        $statement = $connection->query(
            'SELECT asignaciones_turno.id, usuarios.nombre AS empleado, turnos.nombre AS turno,
                    turnos.tipo, turnos.hora_inicio, turnos.hora_fin,
                    turnos.dias_trabajo, turnos.dias_descanso,
                    asignaciones_turno.fecha_inicio, asignaciones_turno.fecha_fin
             FROM asignaciones_turno
             JOIN usuarios ON usuarios.id = asignaciones_turno.usuario_id
             JOIN turnos ON turnos.id = asignaciones_turno.turno_id
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
    'baja_medica' => 'Baja médica',
    'retraso' => 'Retraso',
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
    <meta name="theme-color" content="#111c35">
    <link rel="manifest" href="manifest.json">
    <title>Control horario</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #172033;
            background: #f3f6fb;
            --page-bg: #f3f6fb;
            --surface: #fff;
            --surface-muted: #f7f9fc;
            --text: #172033;
            --muted: #64718a;
            --border: #e3e9f2;
            --input-border: #cbd5e1;
            --shadow: 0 16px 40px rgb(25 42 70 / 7%);
            --sidebar-bg: #111c35;
            --sidebar-text: #e6ecf7;
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --page-bg: #101725;
            --surface: #192437;
            --surface-muted: #202e44;
            --text: #edf2fb;
            --muted: #a4b1c6;
            --border: #2b3a52;
            --input-border: #465773;
            --shadow: 0 16px 40px rgb(0 0 0 / 20%);
            --sidebar-bg: #0b1220;
            --sidebar-text: #e6ecf7;
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
            background: #3156c9;
            font-size: 1.2rem;
        }
        .sidebar-label {
            margin: 0 10px 10px;
            color: #8d9bb4;
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
            background: #3156c9;
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
            color: #a4b1c6;
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
            background: #dc3545;
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
            background: #e9eef8;
            color: #3156c9;
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
            outline: 3px solid #b5caff;
            border-color: #3156c9;
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
        button:focus-visible { outline: 3px solid #91b4ff; outline-offset: 3px; }
        button:disabled { cursor: wait; opacity: .65; }
        .entry-button { background: #147d58; }
        .exit-button { background: #3156c9; }
        .submit-button { background: #3156c9; }
        .approve-button { min-height: 38px; padding: 0 12px; background: #147d58; }
        .reject-button { min-height: 38px; padding: 0 12px; background: #b42318; }
        .export-button {
            display: inline-flex;
            align-items: center;
            min-height: 48px;
            margin-bottom: 16px;
            padding: 0 18px;
            border-radius: 10px;
            background: #147d58;
            color: #fff;
            font-weight: 700;
            text-decoration: none;
        }
        .export-button:hover { background: #106447; }
        .status { margin: 0 0 18px; color: #64718a; }
        .status[data-state="error"] { color: #b42318; }
        .status[data-state="success"] { color: #147d58; }
        .status[data-state="overtime"], .overtime-row { color: #a15c00; font-weight: 700; }
        td[data-state="overtime"] { color: #a15c00; font-weight: 700; }

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
            background: #edf2ff;
            color: #3156c9;
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
        .chart-error { color: #b42318; }
        :root[data-theme="dark"] input,
        :root[data-theme="dark"] select { background: var(--surface-muted); color: var(--text); }
        :root[data-theme="dark"] .logout-button { background: var(--surface-muted); color: #a9bdff; }
        :root[data-theme="dark"] .role-badge { background: #27395c; color: #b8c9ff; }
        :root[data-theme="dark"] .notification-count { color: #fff; }
        :root[data-theme="dark"] .status[data-state="error"] { color: #ff9a9a; }
        :root[data-theme="dark"] .status[data-state="success"] { color: #83e0b2; }
        :root[data-theme="dark"] .status[data-state="overtime"],
        :root[data-theme="dark"] td[data-state="overtime"] { color: #ffcd72; }

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
            .dashboard-grid { gap: 14px; }
            .chart-wrap { min-height: 230px; }
        }
    </style>
    <?php if ($user['rol'] === 'empleado'): ?>
        <script src="app.js" defer></script>
    <?php else: ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <?php endif; ?>
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
                    <a class="sidebar-link" href="#attendance-history"><span class="sidebar-icon" aria-hidden="true">▤</span>Fichajes</a>
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
                                    <th scope="col">DNI</th>
                                    <th scope="col">Dirección</th>
                                    <th scope="col">Correo</th>
                                    <th scope="col">Rol</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users === []): ?>
                                    <tr><td class="empty-row" colspan="5">No hay usuarios registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $registeredUser): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($registeredUser['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($registeredUser['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($registeredUser['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($registeredUser['correo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= $registeredUser['rol'] === 'admin' ? 'Administrador' : 'Empleado' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            </div>

            <section class="card seccion-panel" id="schedules" aria-labelledby="schedule-title">
                <h2 id="schedule-title">Gestión de horarios y turnos</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'shift_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <?php if ($scheduleError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar los horarios.</p>
                <?php elseif ($employees === [] || $shifts === []): ?>
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
                                    <?php foreach ($shifts as $shift): ?>
                                        <option value="<?= (int) $shift['id'] ?>">
                                            <?= htmlspecialchars($shift['nombre'] . ' (' . substr($shift['hora_inicio'], 0, 5) . '–' . substr($shift['hora_fin'], 0, 5) . ')', ENT_QUOTES, 'UTF-8') ?>
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
                <?php if (!$scheduleError): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Empleado</th><th>Turno</th><th>Tipo</th><th>Horario</th><th>Ciclo trabajo/descanso</th><th>Vigencia</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($shiftAssignments === []): ?>
                                    <tr><td class="empty-row" colspan="6">Todavía no hay turnos asignados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($shiftAssignments as $assignment): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($assignment['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($assignment['turno'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= $assignment['tipo'] === 'fijo' ? 'Fijo' : 'Rotativo' ?></td>
                                            <td><?= htmlspecialchars(substr($assignment['hora_inicio'], 0, 5) . '–' . substr($assignment['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int) $assignment['dias_trabajo'] ?> trabajo / <?= (int) $assignment['dias_descanso'] ?> descanso</td>
                                            <td><?= htmlspecialchars($assignment['fecha_inicio'] . ' – ' . ($assignment['fecha_fin'] ?? 'sin fecha de fin'), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card seccion-panel" id="attendance-history" aria-labelledby="attendance-history-title">
                <h2 id="attendance-history-title">Historial de fichajes de la empresa</h2>
                <a class="export-button" href="exportar.php"><span aria-hidden="true">⇩</span> Exportar a Excel (CSV)</a>
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
                                            <td><?= htmlspecialchars($eventLabels[$record['event_type']] ?? $record['event_type'], ENT_QUOTES, 'UTF-8') ?></td>
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

            <section class="card seccion-panel" id="absence-admin" aria-labelledby="absence-admin-title">
                <h2 id="absence-admin-title">Ausencias e incidencias</h2>
                <?php if ($flash !== null && str_starts_with($statusParam, 'absence_')): ?>
                    <p class="status" data-state="<?= htmlspecialchars($flash['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <h2>Registrar baja médica o retraso</h2>
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

                <h2>Mis registros de hoy</h2>
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
                    <div class="form-actions"><button class="submit-button" type="submit"><span aria-hidden="true">▦</span> Enviar solicitud</button></div>
                </form>

                <h2>Mis solicitudes</h2>
                <?php if ($absenceError): ?>
                    <p class="status" data-state="error" role="alert">No se pudieron cargar tus solicitudes.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tipo</th><th>Fechas</th><th>Observaciones</th><th>Estado</th></tr></thead>
                            <tbody>
                                <?php if ($absenceRecords === []): ?>
                                    <tr><td class="empty-row" colspan="4">No has enviado solicitudes ni tienes incidencias registradas.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($absenceRecords as $absence): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($absenceLabels[$absence['tipo']] ?? $absence['tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absence['fecha_inicio'] . ' – ' . $absence['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($absence['observaciones'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                                            <td><?= htmlspecialchars($absenceStatusLabels[$absence['estado']] ?? $absence['estado'], ENT_QUOTES, 'UTF-8') ?></td>
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
