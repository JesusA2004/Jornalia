# Documentación — MR LANA Asistencias

Índice de la documentación técnica y operativa. El punto de entrada general es el [README del repositorio](../README.md).

## Estado y cierre

| Documento | Contenido |
|---|---|
| [PROGRESO_IMPLEMENTACION.md](PROGRESO_IMPLEMENTACION.md) | Estado real por módulo y resultados de verificación |
| [cierre/TRAZABILIDAD_REVISION_RH.md](cierre/TRAZABILIDAD_REVISION_RH.md) | Cada instrucción de la Revisión RH contra código, pruebas y estado |
| [cierre/CIERRE_TECNICO.md](cierre/CIERRE_TECNICO.md) | Cambios por commit, pendientes externos, aprobaciones de RH y Jurídico, contradicciones abiertas |

## Arquitectura

| Documento | Contenido |
|---|---|
| [arquitectura/ARQUITECTURA.md](arquitectura/ARQUITECTURA.md) | Componentes, dominios, flujos y procesos programados |
| [arquitectura/BASE_DE_DATOS.md](arquitectura/BASE_DE_DATOS.md) | Tablas, índices, triggers y configuración de MariaDB |
| [arquitectura/RECTIFICACIONES_Y_FIRMA.md](arquitectura/RECTIFICACIONES_Y_FIRMA.md) | Flujo de 5 pasos, firma electrónica, incidencias |
| [arquitectura/GEOLOCALIZACION.md](arquitectura/GEOLOCALIZACION.md) | Ubicación al marcar, seguimiento por puesto, mapa y depuración |
| [arquitectura/OPERACION_SIN_CONEXION.md](arquitectura/OPERACION_SIN_CONEXION.md) | Cola offline de la app y del kiosco, hora oficial y desfase de reloj |
| [arquitectura/SISTEMA_DISENO.md](arquitectura/SISTEMA_DISENO.md) | Tokens y componentes de la web |

## API

| Documento | Contenido |
|---|---|
| [api/README.md](api/README.md) | Convenciones, endpoints, firma de marcajes y documentos, kiosco, errores |
| [api/openapi.yaml](api/openapi.yaml) | Especificación OpenAPI 3.1 (fuente de tipos: `packages/contracts/src/api.ts`) |

## Legal y cumplimiento

| Documento | Contenido |
|---|---|
| [legal/MOTOR_LEGAL.md](legal/MOTOR_LEGAL.md) | Normativa versionada, cálculos de jornada y tiempo extra, menores, festivos |
| [legal/MATRIZ_CUMPLIMIENTO.md](legal/MATRIZ_CUMPLIMIENTO.md) | Matriz legal con fuentes primarias y puntos que requieren criterio jurídico |
| [cumplimiento/INTEGRIDAD_Y_CONSERVACION.md](cumplimiento/INTEGRIDAD_Y_CONSERVACION.md) | Módulo Cumplimiento: alertas, integridad diaria, bitácora, conservación |

## Seguridad y privacidad

| Documento | Contenido |
|---|---|
| [seguridad/ROLES_Y_PERMISOS.md](seguridad/ROLES_Y_PERMISOS.md) | Roles mínimos, separación de funciones, doble factor, matriz de permisos |
| [seguridad/INTEGRIDAD_REGISTRO.md](seguridad/INTEGRIDAD_REGISTRO.md) | Modelo de amenazas, cadena HMAC, anclas y claves |
| [seguridad/PRIVACIDAD_DATOS.md](seguridad/PRIVACIDAD_DATOS.md) | LFPDPPP: inventario de datos, aviso de privacidad, retención, Google Play |
| [seguridad/BIOMETRIA.md](seguridad/BIOMETRIA.md) | Verificación facial open source: modelos, umbrales, flujo, privacidad |

## Integraciones, notificaciones y reportes

| Documento | Contenido |
|---|---|
| [integraciones/PEOPLE.md](integraciones/PEOPLE.md) | Mr. Lana People: estructura y bajas |
| [integraciones/PEOPLE_INCIDENCIAS.md](integraciones/PEOPLE_INCIDENCIAS.md) | Mr. Lana People: incidencias, permisos, incapacidades y vacaciones |
| [notificaciones/PUSH.md](notificaciones/PUSH.md) | Firebase Cloud Messaging, acuse obligatorio, confirmación de lectura |
| [reportes/REPORTES.md](reportes/REPORTES.md) | Catálogo de reportes, formatos y exportación para NOI |

## Despliegue y operación

| Documento | Contenido |
|---|---|
| [despliegue/DESPLIEGUE.md](despliegue/DESPLIEGUE.md) | Instalación de producción (Ubuntu, Nginx, PHP 8.4-FPM, Supervisor, cron), respaldos |
| [despliegue/ACTUALIZACION_PRODUCCION.md](despliegue/ACTUALIZACION_PRODUCCION.md) | Actualización desde `f26d36f`: respaldo, migraciones, comandos, comprobación y recuperación |
| [despliegue/CI.md](despliegue/CI.md) | Integración continua (GitHub Actions) |
| [manual/MANUAL_OPERATIVO.md](manual/MANUAL_OPERATIVO.md) | Tareas por rol |

## Identidad

| Documento | Contenido |
|---|---|
| [identidad/GUIA_MARCA_DIGITAL.md](identidad/GUIA_MARCA_DIGITAL.md) | Aplicación del manual de identidad y paleta institucional |

`docs/cambios/` contiene material interno de RH y de coordinación entre agentes. Está en `.gitignore` y no se publica.
