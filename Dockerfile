FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY index.php app.js dashboard.js calendar.js manifest.json sw.js offline.html app-icon.svg conexion.php crear_admin_inicial.php crear_usuario.php exportar.php gestionar_ausencias.php gestionar_calendario.php gestionar_plantillas_turnos.php gestionar_turnos.php calendario_eventos.php login.php logout.php registro.php records.php sesion.php restablecer_clave.php ./
