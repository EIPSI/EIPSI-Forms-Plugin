# S4.1 — Reset RCT autorizado

Solo fixtures WordPress/MariaDB desechables con la guarda M0. Ejecutar suites secuencialmente por base. `run-s41.php` añade 22 casos PHP y `s41/consumers.js` ocho casos JS. No contar repeticiones ni el gate S4 como casos nuevos: 1001 + 30 = 1031 (963 PHP + 68 JS).

La prueba de propiedad es una capability HMAC-SHA256 emitida únicamente al crear la fila bajo el mutex canónico. Se liga a sitio, prefijo, ID, config, fingerprint y assigned_at; expira al año. El servidor valida firma, expiración y fila vigente antes de DELETE condicionado y exige exactamente una fila eliminada. Fingerprint y nonce público no autorizan. La segunda eliminación devuelve el mismo error 403 que cualquier prueba inválida; SQL fallido tampoco produce éxito.

El frontend conserva la capability en localStorage por config/fila. Reload y tabs del mismo origen pueden reutilizarla; otro ID no hereda la credencial. Ambos consumidores envían el fingerprint resuelto por servidor. Los botones conservan estado/datos si el reset se rechaza. Una fila previa sin capability, storage borrado o token vencido queda resoluble pero sin reset legítimo por esta vía. No existe recuperación mediante fingerprint ni emisión retrospectiva.

```sh
docker exec eipsi-s41-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s41.php
docker exec -e EIPSI_S4_EXPECT_RCT_DENIAL=1 eipsi-s41-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s4.php
docker run --rm --network none --user 1000:1000 -v "$PWD:/app" -w /app node:22-bookworm node tests/s41/consumers.js
```

Ver compose y scripts de validación en `informes/EIPSI-Forms/2026.10.07 - Evidencias S4.1/` del workspace. Los 22 casos incluyen HTTP anónimo/WP Auth/participante Auth, rechazo ajeno y manipulado, expiración, preexisting, reload, replay, SQL read/delete, shortcode no-cache y cuatro carreras con barriers. Los tests JS ejecutan el asset real con VM y DOM mínimo; no son E2E visual.

No migration: el secreto de firma ya vive en salts WordPress y los claims se verifican contra la fila existente. Salt rotation revoca las capabilities. Tratar el token como bearer: XSS, extensiones o cachés que compartan HTML pueden exponerlo. Excluir páginas RCT de cachés externos que ignoren DONOTCACHEPAGE/headers. No hay revocación individual conservando la fila. Mutex requiere la misma conexión MariaDB y que los escritores canónicos respeten su protocolo. No se certifican datasets legacy ni atomicidad global.
