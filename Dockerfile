FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY index.php app.js dashboard.js profile.js calendar.js manifest.json sw.js offline.html app-icon.svg conexion.php crear_admin_inicial.php crear_usuario.php exportar.php gestionar_ausencias.php gestionar_calendario.php gestionar_plantillas_turnos.php gestionar_turnos.php gestionar_horas_extra.php horario_empleado.php calendario_eventos.php perfil_usuario.php login.php logout.php registro.php records.php resumen_empleado.php auto_close_shifts.php sesion.php restablecer_clave.php ./
