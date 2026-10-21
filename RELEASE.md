# Version 0.2.2-beta

- Componente: `enrol_orderedapply`.
- Version de Moodle: 5.2.x.
- Version interna: `2026102101`.
- Fecha de publicacion: 2026-10-21.

El ZIP de instalacion esta en
`dist/enrol_orderedapply-0.2.2-beta.zip`. Su suma SHA-256 esta en
`dist/SHA256SUMS`.

Esta version corrige el calculo del limite de antigüedad para documentos como
la vida laboral: el dia 15 se acepta y el 16 se rechaza con independencia de
los cambios de horario. Antes de utilizarla en una convocatoria real, seguir
las comprobaciones de `DEPLOYMENT.md` y definir los requisitos documentales,
la informacion de privacidad y el flujo de revision correspondiente.
