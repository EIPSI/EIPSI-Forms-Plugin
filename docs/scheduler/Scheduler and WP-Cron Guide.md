# Scheduler and WP-Cron Guide

## Qué administra EIPSI

Abrí **EIPSI Forms → Configuración → 🕒 Tareas programadas**.

La pantalla se divide en tres pestañas internas:

- **Estado y control:** interruptor de WP-Cron, diagnóstico externo e instrucciones manuales.
- **Actividad y eventos:** últimas ejecuciones, mantenimiento y eventos próximos o atrasados.
- **Configurar cron del servidor:** comandos para copiar e instrucciones por entorno.

La pestaña elegida se conserva al recargar. También podés recorrerlas con las flechas del teclado, Inicio y Fin.

Con WP-Cron por visitas activado, WordPress intenta iniciar las tareas programadas cuando recibe tráfico. Desactivarlo impide ese inicio automático, pero un scheduler externo puede seguir ejecutando WordPress Cron. Ver la [documentación de WordPress](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

Sin visitas ni scheduler externo, los recordatorios, la programación longitudinal, el mantenimiento y la preparación de exports pueden retrasarse o no ejecutarse. Activar WP-Cron tampoco garantiza puntualidad en sitios sin tráfico.

EIPSI observa sus eventos existentes: no crea otra cola ni otro scheduler. La pestaña funciona con External API desactivada. No altera ventanas, expiraciones, tiempos relativos, randomizaciones ni reglas científicas.

## Activar o desactivar

La primera tarjeta muestra el estado efectivo. Si DISABLE_WP_CRON no está definido o su valor es falso, las visitas pueden iniciar cron. Si su valor efectivo lo deshabilita, se muestra Desactivado.

El interruptor sólo se ofrece cuando la configuración puede modificarse con seguridad. Desactivar exige confirmar la advertencia; no exige que exista cron externo confirmado. La pantalla se recarga tras guardar para leer el nuevo estado en otro request.

Si no hay definición y se reconoce un wp-config convencional, EIPSI inserta antes de wp-settings.php:

```php
/* EIPSI Forms — WP-Cron configuration */
define( 'DISABLE_WP_CRON', true );
/* End EIPSI Forms — WP-Cron configuration */
```

Luego sólo alterna ese bloque. No adopta ni sobrescribe definiciones ajenas, aunque sean literales true/false.

La escritura compara el hash esperado, bloquea el original, valida con el parser de PHP sin evaluar el archivo, prepara y sincroniza un temporal privado en el mismo directorio, verifica su contenido, permisos, propietario y grupo, comprueba de nuevo el original y lo reemplaza con rename. Ante un fallo previo conserva el original y elimina el temporal. No deja copias públicas con credenciales.

## Fallback manual y configuraciones especiales

Configuración dinámica, entorno/hosting gestionado, includes alternativos, enlaces simbólicos, archivos de sólo lectura o sin reemplazo seguro: EIPSI muestra instrucciones manuales. El administrador debe cambiar la fuente que ya define DISABLE_WP_CRON, antes de cargar wp-settings.php; no agregar otra definición.

En Docker se modifica la configuración persistente del contenedor; editar sólo el archivo generado puede perderse al recrearlo. En Bedrock/hosting gestionado se utiliza su mecanismo de configuración.

En multisitio el archivo afecta toda la instalación. El interruptor se bloquea, incluso para un administrador de blog; el administrador de infraestructura debe gestionarlo manualmente. La evidencia se guarda por blog y el scheduler debe cubrir los blogs correspondientes.

## Configurar cron del servidor

Frecuencia inicial recomendada: **cada 5 minutos**. Elegí un solo método.

La pantalla ofrece, cuando detecta las rutas, WP-CLI, PHP CLI y un fallback HTTP. Cada comando tiene botón **Copiar** y dos variantes: comando sin frecuencia para cPanel/Plesk y línea completa para crontab.

No detectado significa que este proceso no encontró el ejecutable en PATH, no que no exista. Las rutas de contenedores, entornos restringidos y del servidor anfitrión pueden ser distintas: confirmalas antes de usarlas fuera del entorno detectado.

### cPanel

1. Abrí cPanel → Cron Jobs / Tareas cron.
2. Creá una tarea nueva con minuto */5; hora, día, mes y día de semana *.
3. Pegá sólo el comando elegido, sin los cinco campos de frecuencia, en Comando.
4. Confirmá usuario y rutas del sitio; guardá.
5. Desactivá WP-Cron por visitas si querés delegar la ejecución al scheduler.
6. Esperá dos ejecuciones (aproximadamente diez minutos), volvé a EIPSI y pulsá Verificar cron externo.

### Plesk

1. Abrí Sitios web y dominios → Tareas programadas / Scheduled Tasks.
2. Agregá una tarea de tipo Ejecutar un comando.
3. Definí la frecuencia */5 * * * * y pegá sólo el comando.
4. Usá el usuario del sitio. En entornos restringidos, verificá las rutas con el hosting.
5. Guardá, esperá dos ejecuciones y verificá en EIPSI.

### SSH / crontab

1. Conectate por SSH como el usuario del sitio.
2. Abrí crontab -e.
3. Pegá una sola línea completa de las generadas, incluidos los campos de frecuencia.
4. Guardá y revisá con crontab -l.
5. Esperá dos ejecuciones y verificá desde EIPSI.

Ejemplo ilustrativo; usá las rutas reales detectadas:

```sh
*/5 * * * * cd '/ruta/wordpress' && '/ruta/wp' cron event run --due-now --quiet
```

### WP-CLI

Es el método recomendado si está detectado. Ejecutá el comando con el usuario del sitio y la instalación correcta. WP-CLI documenta [cron event run --due-now](https://developer.wordpress.org/cli/commands/cron/event/). Si no se detecta, pedí la ruta absoluta al administrador. No uses wp eval ni el mantenimiento manual de EIPSI para demostrar que el scheduler funciona.

En multisitio cubrí los blogs correspondientes o utilizá el soporte de red de la versión de WP-CLI instalada.

### PHP CLI

Alternativa ilustrativa:

```sh
*/5 * * * * '/ruta/php' '/ruta/wordpress/wp-cron.php' >/dev/null 2>&1
```

El binario debe ser PHP CLI y acceder a los archivos y a la base. La ruta de PHP-FPM no sirve como sustituto automático.

### Servicio externo HTTP

Un scheduler HTTP puede solicitar la URL de wp-cron.php mostrada en la pantalla, cada cinco minutos. No necesita una clave de External API; EIPSI no agrega secretos al enlace ni configura un proveedor.

El scheduler debe poder acceder a la URL. Un servicio de Internet no puede acceder a tu localhost. Si falla, revisá TLS, firewall, autenticación del hosting, respuesta HTTP y logs.

## Verificación y criterio exacto

Verificar cron externo sólo consulta evidencia anterior: heartbeat, último mantenimiento, resultado seguro, cantidad de eventos EIPSI atrasados y hasta veinte próximos eventos. **No ejecuta cron ni mantenimiento.** Abrir el admin o visitar el frontend tampoco genera evidencia.

El heartbeat observa los diecinueve hooks propiedad de EIPSI en contexto WordPress Cron o wp cron event run. Registra como máximo dos escrituras por request y blog, no por subjob. Mantenimiento API manual puede actualizar sus propias métricas sin confirmar cron externo.

- Frecuencia esperada: 300 segundos.
- Evidencia reciente: hasta 900 segundos inclusive.
- Regularidad externa confirmada: WP-Cron por visitas desactivado y dos requests independientes separados entre 60 y 900 segundos, con el último hace no más de 900 segundos.
- Una sola ejecución, registros futuros, requests casi simultáneos o evidencia vencida: No confirmado.
- Visitas activadas sin evidencia inicial: no es error inmediato.
- Evidencia mayor a quince minutos, fallo observado o eventos EIPSI atrasados más de cinco minutos: Atención.

La finalización de un hook se complementa con errores seguros del mantenimiento API y de la limpieza compartida; no certifica servicios internos de terceros. La misma decisión de salud del scheduler se utiliza en el health administrativo API-3.

Sí significa evidencia de ejecución regular externa en ese contexto. No identifica cPanel/Plesk ni garantiza ejecuciones futuras. No confirmado no significa que el scheduler no exista.

## Prueba humana pendiente

En una instalación descartable, sin estudios ni respuestas reales:

1. Abrir la pestaña y activar WP-Cron.
2. Desactivarlo confirmando la advertencia.
3. Sin scheduler, comprobar No confirmado y la advertencia.
4. Configurar un único cron externo del fixture cada cinco minutos.
5. Esperar dos ejecuciones y verificar timestamp, heartbeat y Sí.
6. Volver a activar WP-Cron y eliminar el cron externo del fixture.

Si la instalación usa configuración externa, revisar primero el fallback; el interruptor se prueba en un fixture convencional. La revisión humana no se marca PASS hasta recibir confirmación del usuario.
