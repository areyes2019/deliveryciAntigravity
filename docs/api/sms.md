# Módulo SMS — Twilio

---

## Configuración

Las credenciales se definen en `.env`:

| Variable | Descripción |
|---|---|
| `TWILIO_SID` | Account SID de la consola de Twilio |
| `TWILIO_TOKEN` | Auth Token de la consola de Twilio |
| `TWILIO_MESSAGING_SID` | Messaging Service SID (MGxxx...) |

> El número de envío (`from`) lo gestiona el Messaging Service de Twilio, no se configura manualmente en el proyecto.

---

## Archivos involucrados

| Archivo | Responsabilidad |
|---|---|
| `app/Services/SmsService.php` | Wrapper del SDK Twilio. Formatea el número, envía el SMS y loggea el resultado |
| `app/Services/NotificationService.php` | Verifica `sms_enabled` del cliente antes de delegar al `SmsService` |
| `app/Controllers/Api/V1/Driver/DriverApiController.php` | Dispara las notificaciones en los eventos del viaje |

---

## Condición para que el SMS se envíe

Los tres requisitos deben cumplirse simultáneamente:

1. El cliente (empresa) tiene `sms_enabled = 1` en la tabla `clients`
2. La orden tiene `receiver_phone` con un número válido
3. Las credenciales de Twilio están configuradas en `.env`

---

## Formato de número aceptado

El `SmsService` convierte automáticamente a formato E.164 para México:

| Entrada | Resultado |
|---|---|
| `4613581090` (10 dígitos) | `+524613581090` |
| `524613581090` (12 dígitos con prefijo 52) | `+524613581090` |
| Cualquier otro formato | Descartado — se loggea como advertencia |

---

## Eventos que disparan SMS

| Evento | Transición | Mensaje |
|---|---|---|
| Conductor asignado | `publicado → tomado` | "Hola {receiver_name}, tu conductor {driver_name} ya fue asignado y va en camino a recoger tu pedido 🚗" |
| Pedido en camino | `arribado → en_camino` | "Tu pedido está en camino 🚗 El conductor {driver_name} se dirige al destino." |
| Pedido entregado | `en_camino → entregado` | "Tu pedido ha sido entregado. ¡Gracias por usar el servicio! 🙌" |

> Los estatus `tomado` y `arribado` **no disparan SMS**.

---

## Logs

Todos los eventos SMS quedan registrados en `writable/logs/log-YYYY-MM-DD.log`:

```
# Envío exitoso
INFO  --> [SmsService] SMS enviado a +524613581090. Twilio SID: SMxxxx

# Número inválido
WARN  --> [SmsService] Número inválido descartado: 123

# Credenciales no configuradas
WARN  --> [SmsService] Credenciales Twilio no configuradas. SMS no enviado.

# Error de Twilio (ej. número no verificado en cuenta Trial)
ERROR --> [SmsService] Error al enviar SMS a +524613581090: ...
```

---

## Restricciones de cuenta Trial

Con una cuenta Trial de Twilio, los SMS **solo se entregan a números verificados**.

Para verificar un número:
1. Entra a [console.twilio.com](https://console.twilio.com)
2. Ve a **Phone Numbers → Verified Caller IDs**
3. Agrega el número y confirma con el código que Twilio envía

Para enviar a cualquier número sin restricciones, activa la cuenta agregando un método de pago en la consola de Twilio.
