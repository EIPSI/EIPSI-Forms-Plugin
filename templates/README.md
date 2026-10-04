# Plantillas JSON

La biblioteca importa y exporta formularios mediante [form-library-tools.php](../admin/form-library-tools.php). Este código es la referencia de validación y conversión; no todos los formatos representan lo mismo.

## Formatos actuales y compatibilidad

- TRUE Lite actual: raíz `version: "2.0"`, `meta` y `structure`. La estructura contiene páginas/campos y se convierte a bloques Gutenberg al importar.
- Legacy: raíz `schemaVersion` y `form`. El importador distingue FULL con `form.postContent` de LITE basado en `form.blocks`.
- La exportación Lite actual produce TRUE Lite; `EIPSI_FORMS_ENABLE_LEGACY_EXPORT` permite la alternativa legacy prevista por el código.

Estas versiones son del formato JSON. `meta.pluginVersion` registra el origen del archivo y no acredita una publicación pública.

## Crear y transferir

Crear el formulario en Gutenberg y exportarlo desde la biblioteca es la forma de obtener atributos coherentes con el código actual. Para editar JSON manualmente, consultar los metadatos `src/blocks/*/block.json` y la validación del importador. Importar primero en una instalación descartable y revisar el resultado antes de usar datos reales.

[example-minimal-lite.json](example-minimal-lite.json) es un ejemplo **legacy**, no el formato por defecto actual. [EXAMPLES.md](EXAMPLES.md) explica su papel y el de los ejemplos conservados. Los fixtures JSON no se modificaron en la purga documental.
