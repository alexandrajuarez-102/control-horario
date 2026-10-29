FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY index.php app.js dashboard.js manifest.json sw.js offline.html app-icon.svg conexion.php crear_admin_inicial.php crear_usuario.php exportar.php gestionar_ausencias.php gestionar_turnos.php login.php logout.php registro.php records.php sesion.php ./
