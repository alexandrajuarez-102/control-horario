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
todos los registros como CSV compatible con Excel. La fecha y hora exportadas
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
y registro manual de bajas médicas y retrasos. Los empleados pueden solicitar
vacaciones, citas médicas o asuntos familiares y consultar el estado. Las horas
trabajadas se calculan al fichar salida, descontando pausas; cualquier tiempo
por encima de ocho horas se almacena como horas extra. Esta regla es un umbral
configurable de prototipo y no determina por sí sola el tratamiento laboral o
retributivo de las horas extra.

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