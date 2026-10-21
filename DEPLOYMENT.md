# Despliegue de enrol_orderedapply

Este repositorio contiene un unico plugin de matriculacion Moodle. La carpeta
raiz del repositorio es la carpeta `orderedapply`; no debe quedar otra carpeta
`orderedapply` anidada al copiarlo.

## Compatibilidad

- Moodle 5.2.x (`$plugin->supported = [502, 502]`).
- Version del plugin: 0.2.1-beta (`2026102100`).
- PHP y motor de base de datos compatibles con la instalacion de Moodle.

No instalar directamente en produccion sin haberlo probado antes con la misma
rama de Moodle y una base de datos de pruebas.

## Instalacion por consola

1. Hacer copia de seguridad de la base de datos y de `enrol/`.
2. Copiar este repositorio completo a `DIRROOT/enrol/orderedapply`.
3. Dejar el codigo propiedad del usuario de despliegue y legible por el usuario
   del servidor web. No dar permisos `777` ni hacer escribible todo Moodle.
4. Ejecutar la actualizacion como el usuario de servicio:

```sh
sudo -u www-data php DIRROOT/admin/cli/upgrade.php --non-interactive
```

5. En Moodle, ir a Administracion del sitio > Plugins > Matriculaciones >
   Gestionar plugins de matriculacion y habilitar `Inscripcion y plazas`.
6. Ejecutar el cron de Moodle y confirmar que aparece la tarea `Asignar plazas
   y enviar avisos de solicitudes`.

En Moodle con estructura `public/`, `DIRROOT` es la ruta configurada en
`$CFG->dirroot`; no hay que deducirla por el nombre del directorio web.

## Configuracion por curso

1. Crear una convocatoria o curso de pruebas.
2. En Participantes > Metodos de matriculacion, anadir `Inscripcion y plazas`.
3. Indicar cupo, fechas, privacidad, instrucciones y requisitos documentales.
4. Desactivar los otros metodos de matriculacion solo en ese curso antes de
   abrir solicitudes. El plugin bloquea la asignacion si detecta otra via
   activa.
5. Asignar a quien revisa la capacidad `enrol/orderedapply:review` en el
   contexto del curso o de la categoria.
6. Hacer la prueba completa con cuentas ficticias: solicitud, documentos,
   subsanacion, revision, cupo, retirada, correo y acceso al curso.

No abrir una convocatoria real sin una URL de privacidad aprobada, criterios
documentales definidos y cron/correo saliente verificados.

## Actualizacion y reversión

Antes de sustituir una version instalada, guardar el codigo actual y una copia
de la base de datos. Despues de copiar la nueva version, ejecutar siempre
`admin/cli/upgrade.php`. Para volver atras, restaurar codigo y base de datos de
la misma copia; no mezclar una version anterior del codigo con una base de
datos actualizada.

La version 0.2.1-beta corrige el envio de autorizaciones de consulta sin
adjunto y la visibilidad del editor de requisitos. No modifica las solicitudes
existentes ni sus documentos.
