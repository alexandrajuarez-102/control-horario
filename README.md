# Control horario

Prototipo educativo de registro de inicio y fin de jornada. La base guarda la
hora asignada por el servidor en UTC y, por separado, la hora comunicada por el
navegador como dato auxiliar. La pantalla muestra la hora de `Europe/Madrid`.

## Ejecutar con XAMPP

1. Copia los archivos del proyecto a una carpeta dentro de `htdocs`.
2. Inicia Apache y MySQL desde el panel de XAMPP.
3. Importa `database.sql` desde phpMyAdmin o ejecuta su contenido en MySQL.
4. Abre `http://localhost/<carpeta-del-proyecto>/`; la página solicitará iniciar
   sesión si todavía no hay una sesión activa.

La conexión PHP usa por defecto `127.0.0.1`, base de datos `control_horario`,
usuario `root` y contraseña vacía (configuración predeterminada de XAMPP). El
esquema no crea credenciales de administrador. Después de importar la base,
crea el primer administrador desde PowerShell con valores propios:

```powershell
$env:ADMIN_NAME = Read-Host "Nombre del administrador"
$env:ADMIN_EMAIL = Read-Host "Correo del administrador"
$securePassword = Read-Host "Contraseña (mínimo 7 caracteres)" -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $env:ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    php .\crear_admin_inicial.php
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:ADMIN_NAME, Env:ADMIN_EMAIL, Env:ADMIN_PASSWORD
}
```

El script solo puede ejecutarse por CLI, solo crea el administrador si aún no
hay usuarios y almacena la contraseña con `password_hash()`.

## Ejecutar con Docker

Desde la carpeta del proyecto, copia `.env.example` a `.env`, escribe valores
aleatorios propios para `MYSQL_PASSWORD` y `MYSQL_ROOT_PASSWORD`, y ejecuta
`docker compose up --build -d`. Abre `http://localhost:8081` (o el puerto
configurado en `APP_PORT`). La base de datos conserva sus datos en el volumen
`mysql_data`. No compartas el archivo `.env`; los scripts de inicialización de
MySQL solo se ejecutan cuando se crea el volumen por primera vez.

Para crear el primer administrador después de levantar los servicios, establece
sus datos temporalmente en PowerShell y ejecuta el script dentro del contenedor:

```powershell
$env:ADMIN_NAME = Read-Host "Nombre del administrador"
$env:ADMIN_EMAIL = Read-Host "Correo del administrador"
$securePassword = Read-Host "Contraseña (mínimo 7 caracteres)" -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $env:ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    docker compose exec -e ADMIN_NAME -e ADMIN_EMAIL -e ADMIN_PASSWORD web php crear_admin_inicial.php
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:ADMIN_NAME, Env:ADMIN_EMAIL, Env:ADMIN_PASSWORD
}
```

El script rechaza la operación si ya existe cualquier usuario en la base.

Para aplicar las nuevas tablas y columnas a una base Docker existente sin
destruir fichajes ni usuarios, ejecuta desde la carpeta del proyecto:

```powershell
docker compose cp database.sql db:/tmp/control-horario-schema.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /tmp/control-horario-schema.sql && rm /tmp/control-horario-schema.sql'
docker compose up --build --detach
```

## Alcance legal y seguridad

El acceso se separa por rol: los administradores pueden dar de alta usuarios y
ver la lista de cuentas; los empleados solo pueden fichar y consultar sus propios
registros del día. El alta valida permisos y token CSRF en el servidor, usa
consultas preparadas y guarda la contraseña con `password_hash()`. Las
contraseñas nuevas deben tener al menos 7 caracteres.

El panel de administración muestra el historial de fichajes y permite descargar
registros individuales o de todos los trabajadores como CSV compatible con
Excel, filtrados por cualquier intervalo de fechas. Cada empleado puede consultar
un resumen mensual con totales por semana y descargar el detalle de fichajes de
un único día; las pausas no se repiten en el resumen. La fecha y hora exportadas
usan la zona horaria `Europe/Madrid`; el CSV incluye nombre, DNI, tipo de evento
y coordenadas, horas trabajadas y horas extra, y protege los campos de texto
frente a fórmulas de hoja de cálculo.

Los empleados pueden fichar entrada, salida e inicio/fin de pausa. Cada nuevo
fichaje requiere permiso de geolocalización y guarda las coordenadas informadas
por el navegador. El navegador normalmente exige HTTPS para geolocalización,
excepto en `localhost`. Las coordenadas del dispositivo no demuestran por sí
solas la identidad o presencia física del trabajador; explica su uso y
tratamiento antes de habilitarlo en un entorno real.

El panel admin incluye plantillas de turno fijas y rotativas (5x2, 4x2 y 6x3),
asignaciones por vigencia sin solapamientos, revisión de solicitudes de ausencia
y registro manual de bajas médicas, retrasos y faltas. Los empleados pueden solicitar
vacaciones y distintos permisos familiares, médicos, personales o de conciliación,
consultar el estado y modificar o eliminar sus solicitudes mientras sigan pendientes.
El catálogo es orientativo: los requisitos, duración y aprobación dependen de la
normativa vigente, el convenio y la política de la empresa; no constituye asesoramiento
legal ni garantiza que un permiso concreto sea retribuido. Las horas
trabajadas se calculan al fichar salida, descontando pausas; cualquier tiempo
por encima de ocho horas se almacena como horas extra. Esta regla es un umbral
configurable de prototipo y no determina por sí sola el tratamiento laboral o
retributivo de las horas extra.

El perfil de cada empleado permite guardar un cargo opcional de hasta 100
caracteres. Los fichajes requieren un turno laborable asignado y la entrada solo
se admite dentro de su horario. Para finalizar después de la hora de salida, el
empleado debe solicitar los minutos adicionales con su motivo y esperar la
aprobación del administrador; se autorizan como máximo 12 horas extra por fecha.
También se contemplan turnos nocturnos que terminan al día siguiente.
Un servicio programado revisa los turnos vencidos cada minuto y registra una
salida automática al final del turno (o del tiempo extra autorizado) únicamente
si existe una entrada real sin cierre. No crea entradas ni completa jornadas
que el empleado nunca inició. Las salidas automáticas se identifican como tales
en los registros y descargas.

Administración permite crear plantillas fijas o rotativas con horarios y ciclos
personalizados; las asignaciones siguen vinculadas a la tabla de turnos existente
para conservar las asignaciones previas. La pestaña Calendario muestra eventos y
festivos compartidos, y solo administración puede añadirlos. FullCalendar se
carga desde CDN, por lo que su interfaz requiere conexión a Internet.

En una base Docker ya creada, aplica primero el esquema adicional y, si quieres
cargar datos ficticios, importa `datos_prueba.sql` desde la carpeta del proyecto:

```powershell
docker compose cp migracion_funcionalidades.sql db:/tmp/migracion-funcionalidades.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /tmp/migracion-funcionalidades.sql && rm /tmp/migracion-funcionalidades.sql'
docker compose cp migracion_cargos_horas_extra.sql db:/tmp/migracion-cargos-horas-extra.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /tmp/migracion-cargos-horas-extra.sql && rm /tmp/migracion-cargos-horas-extra.sql'
docker compose cp migracion_cierre_automatico.sql db:/tmp/migracion-cierre-automatico.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /tmp/migracion-cierre-automatico.sql && rm /tmp/migracion-cierre-automatico.sql'
docker compose cp datos_prueba.sql db:/tmp/datos-prueba.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /tmp/datos-prueba.sql && rm /tmp/datos-prueba.sql'
docker compose up --build --detach
```

La migración de funcionalidades conserva usuarios, fichajes y asignaciones, y sincroniza las
plantillas iniciales desde los turnos existentes. El SQL de demostración se
puede volver a ejecutar sin volver a crear sus usuarios, asignaciones o
ausencias; requiere que ya exista un administrador y no crea ni actualiza
ninguna cuenta de administración. Los nombres, correos bajo `example.test`,
documentos y direcciones son ficticios. Las contraseñas de las cuentas de prueba
no se proporcionan.

El panel administrativo incorpora navegación lateral adaptable a móvil, un
conmutador de tema claro/oscuro cuya preferencia se guarda en el navegador, y
gráficos de actividad y estado estimado del personal. Chart.js se carga desde
su CDN únicamente en la vista administrativa. Estas mejoras no requieren
cambios en el esquema de base de datos.

La aplicación incluye un manifiesto y un Service Worker para permitir su
instalación y ofrecer una página de fallback sin conexión. El Service Worker
solo almacena recursos estáticos; no guarda páginas PHP autenticadas, sesiones
ni respuestas de fichajes. La instalación requiere un contexto seguro (HTTPS o
`localhost`) y el modo sin conexión no permite consultar ni registrar fichajes.

La información de ausencias, especialmente la relacionada con salud, requiere
controles de acceso y una política de minimización/retención adecuados. No
incluyas diagnósticos ni detalles médicos en las observaciones de este prototipo.

El esquema no borra automáticamente los fichajes y permite conservarlos durante
al menos cuatro años. El prototipo ofrece inicio de sesión y separación básica
por roles, pero no verifica la identidad del trabajador, no mantiene auditoría
de modificaciones ni copias de seguridad, y no define una política completa de
retención/borrado. Tampoco se debe considerar el reloj del navegador como
evidencia oficial: el servidor asigna la hora persistida como hora del fichaje.

Por tanto, este código es una base para un trabajo académico, no una solución
lista ni una garantía de cumplimiento del Real Decreto-ley 8/2019. Antes de
usarlo en producción, hay que definir y validar con asesoramiento adecuado la
identificación de empleados, controles de acceso, disponibilidad para las
personas trabajadoras y representantes, conservación durante cuatro años,
protección de datos y trazabilidad de cualquier rectificación.