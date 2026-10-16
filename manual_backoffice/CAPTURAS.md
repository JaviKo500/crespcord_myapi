# Cómo sacar capturas de WhatsApp para este manual

Documento interno para quien **mantiene** el manual del back office. No se publica: `scripts/build-manual.sh` solo convierte `USER_GUIDE.md` y `guia/*.md`.

Las capturas del asistente de WhatsApp de este manual (por ejemplo, la de [Pagos que llegan por WhatsApp](guia/02_pagos.md#pagos-que-llegan-por-whatsapp)) salen de `manual_bot/assets/`, el manual del bot. Para añadir otras nuevas hay tres opciones.

## Opción 1 · Reusar las del manual del bot (la recomendada)

`manual_bot/assets/` ya tiene capturas reales de todo el recorrido: saludo, envío del comprobante, preguntas, resumen, pago registrado, errores…

1. Elige la captura en `manual_bot/assets/`.
2. Cópiala a `manual_backoffice/assets/` con un nombre que empiece por `bot_` (por ejemplo, `bot_resumen.png`).
3. Enlázala desde la página que corresponda.

Ventaja: ya están revisadas y sin datos personales. Inconveniente: si el bot cambia sus textos, hay que repetirlas en los dos manuales.

## Opción 2 · WhatsApp Web en el ordenador

Sirve para capturas nuevas a buen tamaño y con el mismo aspecto que las existentes (tema oscuro).

1. En el ordenador, abre **web.whatsapp.com** con el **número de prueba** y vincúlalo escaneando el código QR desde el teléfono.
2. Abre el chat con el número del asistente.
3. Haz el recorrido con un comprobante de prueba, nunca uno real.
4. Captura solo la zona del chat:
   - **Mac**: `Cmd + Shift + 4` y arrastra.
   - **Windows**: `Win + Shift + S`.
5. Antes de guardarla, difumina o recorta el número de teléfono y el nombre del contacto.
6. Guárdala en `manual_bot/assets/` (si es del bot) o en `manual_backoffice/assets/`.

La sesión de Chrome puede manejarla Claude: si WhatsApp Web ya está abierto con el número de prueba, puede hacer el recorrido y las capturas.

## Opción 3 · Desde el teléfono

Para capturas con aspecto de móvil.

1. Usa el teléfono con el **número de prueba**.
2. Haz el recorrido en el chat del asistente.
3. Captura la pantalla:
   - **iPhone**: botón lateral + subir volumen.
   - **Android**: encendido + bajar volumen.
4. Pásalas al ordenador (AirDrop, cable o una carpeta compartida) y déjalas en una carpeta del repo.
5. Recórtalas y difumina el número y el nombre del contacto antes de añadirlas al manual (Claude puede hacerlo si se le pide).

## Reglas para cualquier captura

- **Nunca** uses comprobantes, teléfonos, cédulas ni nombres reales.
- Usa siempre el número de prueba y los condominios de prueba (*PRUEBAS MOTORA*, *Condominio jk*).
- Nombres de archivo en minúsculas, con guiones bajos y que digan lo que muestran: `bot_pago_registrado.png`, no `IMG_2041.png`.
