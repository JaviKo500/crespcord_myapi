# Manual del back office

Esta guía explica, paso a paso y con capturas reales, cómo usar el sitio web de administración de CrespCord (el *back office*) para atender lo que los residentes hacen desde la app y desde el asistente de WhatsApp: verificar pagos, dar seguimiento a reclamos, revisar reservas, supervisar los servicios de proveedores y publicar boletines.

> Las capturas se tomaron en el servidor de pruebas (**MOTORA-DESARROLLO**) con dos cuentas de prueba, una por rol. Los condominios *PRUEBAS MOTORA* y *Condominio jk*, los importes y los textos que empiezan por «E2E» o «[Prueba manual]» son datos de prueba. Los nombres, cédulas, teléfonos y correos aparecen difuminados. Los recuadros naranjas son resaltados añadidos para el manual.

## ¿Qué rol tengo?

El back office muestra cosas distintas según el rol de tu usuario:

| | **Backend** | **Administrador edificio** |
|---|---|---|
| Para quién | La administración central: ve todos los condominios. | Quien gestiona uno o varios edificios concretos. |
| Qué condominios ve | Todos. | Solo los condominios que tiene asignados. |
| Pagos | Revisa, aprueba y anula pagos. Reporte de pagos y CSV. | No tiene acceso. |
| Reclamos | Ve, edita y da seguimiento. | Igual, pero solo de sus condominios. |
| Reservas y áreas | Listado y calendario. | Listado y calendario de sus condominios. Crea reservas y áreas. |
| Servicios de proveedores | Solicitudes, proveedores, ofertas y calificaciones. | Solo consulta las solicitudes de sus condominios. |
| Boletines | Crea boletines generales, por condominio o personalizados. | Crea boletines solo de tipo *Condominio*, para sus condominios. |

## Índice

1. [Entrar y moverse por el back office](guia/01_acceso_y_menu.md)
2. [Pagos: reporte, verificación y pagos por WhatsApp](guia/02_pagos.md) · *solo backend*
3. [Reclamos y requerimientos](guia/03_reclamos.md)
4. [Reservas, calendario y áreas comunes](guia/04_reservas.md)
5. [Servicios de proveedores](guia/05_servicios.md)
6. [Boletines](guia/06_boletines.md)

## Otros manuales

- [Manual de la app](/manual/): lo que ve el residente en el teléfono.
- [Manual del asistente de WhatsApp](/manual/whatsapp/): cómo un residente registra un pago enviando el comprobante.

## Qué no cubre este manual

- Las secciones del menú de backend que **no** forman parte de este módulo (Gestor Bancos, Importar, Alícuotas por enviar, los reportes y exportaciones de alícuotas, gastos, ingresos, ocupantes y propietarios, etc.). Siguen funcionando como siempre.
- La configuración del sitio (roles, permisos, menús, usuarios).
