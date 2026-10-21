# Inscripción y plazas — enrol_orderedapply

Versión 0.2.1-beta. Complemento independiente de matriculación para Moodle 5.2.1 o superior dentro de la rama 5.2. Interfaces en español e inglés. Licencia GPL v3 o posterior.

## Corrección 0.2.1 pendiente de aceptación

La prueba web del 21 de octubre detectó que la autorización de consulta del padrón no se convertía correctamente cuando Moodle omitía el campo de archivo oculto. Esta versión conserva la vía de consulta y el consentimiento aunque no exista un campo de archivo en el envío. También sustituye una condición de visibilidad no admitida en el editor de documentos por condiciones de igualdad compatibles.

Se añaden dos regresiones de Moodle. La conversión se ha comprobado con siete verificaciones aisladas en PHP y la sintaxis es correcta. La suite completa de Moodle no se ha podido repetir porque el servicio Docker local no está disponible. Los resultados de 33 pruebas y 92 comprobaciones corresponden a 0.2.0, no certifican esta corrección. Instalar primero en pruebas, repetir la suite completa y el circuito web con adjuntos y autorizaciones antes de abrir una convocatoria.

Permite incorporar cursos futuros y reutilizar el mismo instalador en otras plataformas Moodle compatibles. No contiene nombres de ayuntamientos, identificadores de cursos ni credenciales. No depende de Enrolment Request ni modifica su código.

## Qué incluye

- Portal de solicitudes: `/enrol/orderedapply/index.php`.
- Solicitud por curso con datos del perfil, identificación y teléfono. Un usuario debe disponer de cuenta confirmada; esto no lo matricula ni le da acceso al contenido del curso.
- Hasta 20 tipos de documento por curso, configurables mediante campos normales: nombre, obligatoriedad, archivo o autorización de consulta, texto de autorización y antigüedad máxima.
- Un PDF/JPG/PNG por tipo, con límite de tamaño. Comprobación de fecha declarada; la persona revisora contrasta la fecha real del documento.
- Documentos privados accesibles por su titular o por personas con permiso expreso de revisión.
- Revisión documental, subsanación, rechazo y retirada; historial con persona revisora y fecha.
- Cupo por curso, 25 por defecto, y asignación por primera solicitud. Empates en el mismo segundo se resuelven por identificador de entrada.
- Bloqueo por curso y transacción para serializar aprobaciones, solicitudes y retiradas. El cupo se comprueba de nuevo al matricular.
- Avisos por la mensajería de Moodle mediante una cola transaccional y una tarea periódica.
- Exportación y borrado mediante la API de privacidad de Moodle.
- Copia de configuración al restaurar cursos conservando sus métodos de matrícula: la convocatoria restaurada queda desactivada y cerrada, con privacidad pendiente de configurar. No se exportan solicitudes, autorizaciones, documentos ni historial del complemento. No se restauran matrículas de este método sin su correspondiente solicitud validada.

## Instalación en pruebas

1. Instalar el ZIP desde Administración del sitio → Extensiones → Instalar complementos. También se puede extraer `orderedapply` en `public/enrol/orderedapply/` y ejecutar el procedimiento de actualización de Moodle.
2. Habilitar **Inscripción y plazas** en Gestionar plugins de matriculación.
3. Crear un curso de prueba vacío. No hace falta restaurar un curso real ni su contenido SCORM.
4. En Participantes → Métodos de matriculación, añadir **Inscripción y plazas**.
5. Indicar cupo, fechas, documentos e instrucciones. Añadir la URL de privacidad aprobada por la entidad.
6. Desactivar los demás métodos de matrícula **en ese curso**. El complemento avisa y bloquea la recepción/asignación si encuentra otra vía activada. No las desactiva automáticamente.
7. Activar el método y abrir la recepción de solicitudes.
8. Publicar un enlace al portal en el menú del sitio o en la página inicial.
9. Configurar una persona revisora con `enrol/orderedapply:review` en el contexto del curso o su categoría. Los gestores lo reciben por defecto; los profesores no reciben acceso a documentos privados por defecto.
10. Comprobar cron y correo saliente. La tarea **Asignar plazas y enviar avisos de solicitudes** se programa cada minuto.

Se recomienda usar un curso nuevo para cada convocatoria. Un curso con alumnado previo puede contener matrículas de otros métodos que dejen de estar activas al desactivarlos: no debe transformarse una convocatoria existente sin preparar esa transición. Las matrículas activas previas cuentan para el cupo. Los administradores y gestores acceden por sus permisos, no necesitan una plaza de alumno para revisar.

## Regla de turnos de esta beta

Los primeros puestos equivalentes a las plazas libres están reservados mientras su solicitud esté pendiente, en subsanación o validada. Validar una solicitud posterior no adelanta su turno. Cada solicitud validada dentro de esos puestos se matricula automáticamente; otras solicitudes anteriores sin resolver conservan sus propias reservas.

Un rechazo o una retirada libera el puesto y permite pasar a la siguiente solicitud validada. Una subsanación conserva la fecha y el turno. El plazo de subsanación es opcional; al vencer se impide editar, pero no se rechaza automáticamente ni se pierde el turno: debe resolverlo el equipo revisor. Una nueva solicitud después de retirarse recibe un turno nuevo. Una solicitud rechazada no se puede presentar de nuevo en la misma convocatoria.

Esta es una regla inicial conservadora: debe confirmarse con la entidad organizadora antes de abrir inscripciones reales. Para cambiar una decisión por error después de matricular, debe prepararse una operación específica; esta beta no ofrece una revocación administrativa que cambie silenciosamente la cola.

## Datos y diplomas

Nombre, apellidos y correo se toman de la cuenta autenticada; no se admite suplantarlos enviando campos manipulados. Se guarda una instantánea de esos datos con la solicitud. La persona puede revisar su perfil antes de enviar.

El complemento se ocupa de la admisión y matrícula. La emisión automática del diploma sigue correspondiendo a la actividad de certificado y a sus condiciones de finalización del curso. En esta beta, la identificación se guarda en la solicitud: no se copia automáticamente a un campo de perfil ni se inserta en un diploma. Si el diploma debe incluirla, hace falta acordar el campo y configurar/probar esa integración. Tampoco configura evaluaciones ni condiciones de superación del SCORM.

## Documentación y consulta administrativa

Cada documento puede exigir archivo o permitir elegir entre adjuntar y autorizar consulta. La autorización guarda el texto exacto y su fecha. Dar esa autorización **no consulta automáticamente** padrón, LABORA ni otros sistemas: el personal autorizado realiza la consulta y después valida. No hay conexiones externas ni exportación de adjuntos por correo.

Para Castellón, el criterio confirmado es: padrón adjunto o autorización de consulta; y, para situación laboral, una de estas alternativas: vida laboral con antigüedad máxima de 15 días, DARDE/LABORA adjunto o autorización de consulta de LABORA. El límite de 15 días solo se aplica al archivo de vida laboral. Los textos exactos de autorización y el enlace de privacidad municipal siguen pendientes de facilitar. Estas reglas se configuran por curso y no están impuestas en el código.

La casilla de privacidad registra lectura de la información enlazada; no sustituye una definición de base jurídica o de plazo de conservación. La configuración de documentos se bloquea tras recibir la primera solicitud para que todas se evalúen con los mismos requisitos.

## Límites y operaciones

- El cupo se garantiza para las operaciones de este complemento con una configuración exclusiva. Otra integración, un administrador o una escritura directa en la base de datos puede saltarse ese circuito; no se modifica el núcleo de Moodle para impedir acciones de administración.
- Una cuenta puede solicitar un curso una vez por convocatoria. Se pueden solicitar varios cursos: la beta no impone un máximo global de cursos por persona.
- Los avisos pasan por Moodle. Cron y la configuración SMTP determinan cuándo llega el correo. Una caída justo después de entregar un aviso y antes de marcarlo enviado puede producir un aviso repetido; no se promete entrega exactamente una vez.
- Los mensajes no incluyen adjuntos ni notas de revisión; enlazan al estado de la solicitud tras autenticarse.
- El historial conserva estados y decisiones, no versiones de documentos sustituidos. La subsanación reemplaza los adjuntos anteriores; las áreas temporales de carga se eliminan con la limpieza normal de Moodle.
- No hay borrado automático por antigüedad. La entidad debe definir conservación y gestionar las solicitudes de privacidad mediante Moodle. Las copias completas del servidor pueden contener estos datos y requieren la misma gestión que el resto de Moodle.
- No hay migración automática desde Enrolment Request, formularios existentes ni convocatorias de otra plataforma. El ZIP es reutilizable; las solicitudes personales no viajan al copiar un curso.
- Compatibilidad comprobada en PostgreSQL y PHP 8.3. Debe repetirse la aceptación si cambia el motor de base de datos, la configuración de bloqueos o la rama mayor de Moodle.

## Validación antes de abrir una convocatoria real

Probar en la plataforma de pruebas con cuentas ficticias: alta/confirmación de cuenta, documentos y autorización, subsanación, revisión con dos gestores, cupo y lista de espera, retirada y aviso recibido. Comprobar también el acceso al contenido antes/después de matrícula y la emisión del diploma al superar el curso. Confirmar los requisitos y las reglas de turnos con el equipo municipal.

Las pruebas automáticas de esta entrega cubren el servicio de admisión, los formularios y la copia de configuración. No equivalen a haber validado SMTP real, una conexión administrativa externa o la plantilla del diploma del Ayuntamiento.

## Pruebas para desarrollo

En una instalación de desarrollo con Composer y la base de datos exclusiva de PHPUnit configurada:

```sh
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit public/enrol/orderedapply/tests/service_test.php
```

No ejecutar la inicialización de PHPUnit usando el prefijo o el directorio de datos de una plataforma real. La prueba de concurrencia lanza dos procesos PHP con el entorno exclusivo de PHPUnit y comprueba dos aprobaciones para la última plaza disponible.

Moodle 5.2 usa `public/` para el directorio web. Los bloques usan la API estándar de bloqueos de Moodle; en instalaciones con varios servidores deben compartir el mecanismo de bloqueo configurado.

## Cambios de 0.2.0-beta

- Dos alternativas documentales para el mismo requisito, cada una con su antigüedad y su autorización de consulta opcional. En el formulario se elige una sola alternativa; no se exigen ambos documentos.
- Al configurar «Aportar una de dos alternativas», indique nombre y antigüedad de cada una. Deje en cero la antigüedad que no deba comprobarse. Si una alternativa permite consulta, añada su texto de autorización aprobado; si lo deja vacío, esa alternativa solo admite archivos.
- Corrección del selector de archivos para personas sin matrícula: las cargas temporales usan el contexto personal de la cuenta. Los documentos enviados siguen guardados privadamente en el contexto del curso. En 0.1 el selector podía fallar con «Curso o actividad no accesible».
- 33 pruebas automáticas y 92 comprobaciones por ejecución, tanto en Moodle 5.2.1 como en 5.2.4+. Incluyen la página real de solicitud sin matrícula, alternativas y autorizaciones. La comprobación del selector en el alojamiento queda pendiente de actualizar el código allí.

La actualización conserva las solicitudes existentes. Como los requisitos de una convocatoria con solicitudes están congelados, para probar una combinación documental distinta se debe utilizar un curso/convocatoria de prueba nuevo.
