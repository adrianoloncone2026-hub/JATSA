# API — Cuenta (módulo 3)

Registro de cliente/empresario y «Mi cuenta». Formato de respuesta: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. `campos` aparece solo en errores de formulario y mapea cada campo a su mensaje (`{ "telefono": "El teléfono tiene 9 dígitos." }`).

Toda petición que modifica datos exige la cabecera `X-Requested-With: JATSA` (ver `auth.md`).

## POST `/api/cuenta/registro` (público)
Crea una cuenta. **No abre sesión**: después la persona ingresa con su usuario.

```json
{ "tipoDocumento": "DNI", "numeroDocumento": "71889345", "nombreCompleto": "María Elena Rojas Ccori",
  "telefono": "987654323", "telefonoAuxiliar": "", "correo": "", "usuario": "mariarojas", "contrasena": "Segura#2026" }
```
`201 { "data": { "usuario": "mariarojas", "rol": "CLIENTE" } }`

| Regla | Detalle |
|---|---|
| Rol | `DNI` → `CLIENTE`; `RUC` → `EMPRESARIO` (RF03). `CE` y `PAS` no se registran desde la web |
| Documento | DNI 8 dígitos, RUC 11 (RF05). Se quitan espacios y guiones |
| Nombre | Obligatorio, hasta 150 caracteres; los espacios repetidos se reducen a uno |
| Teléfono | 9 dígitos. Auxiliar opcional, 9 dígitos y distinto del teléfono (`CK_PERSONA_TELAUX`) |
| Correo | Opcional, válido, hasta 120 caracteres |
| Usuario | 4 a 30 caracteres: letras, números, `.`, `-`, `_`. Único sin distinguir mayúsculas |
| Contraseña | 8 a 72 caracteres (límite de bcrypt), distinta del usuario. Se guarda con `password_hash` (bcrypt, coste 12) |
| Persona existente | Si ya hay una persona con ese documento **sin cuenta**, se reutiliza con **sus datos guardados**: no se sobrescribe nada de ella (RF04, «usamos tus datos guardados») |
| Escritura | Persona (si es nueva) y usuario en **una transacción**; `Usuario.idSucursal = NULL` (`CK_USUARIO_SUCURSAL`) |

| Estado | Código | Cuándo |
|---|---|---|
| 422 | `datos_invalidos` | Algún campo no cumple; `campos` trae **todos** los errores a la vez |
| 409 | `cuenta_existente` | El documento ya tiene una cuenta |
| 409 | `usuario_en_uso` | El usuario ya existe (`campos.usuario`) |

## GET `/api/cuenta` · PATCH `/api/cuenta` · POST `/api/cuenta/contrasena`
**GET y PATCH `/api/cuenta`:** solo **CLIENTE** y **EMPRESARIO** (`RequiereRol`); otros roles reciben `403 acceso_denegado` y sin sesión `401`. **POST `/api/cuenta/contrasena`:** cualquier rol con **sesión vigente** (GERENTE, ADMINISTRADOR, TRANSPORTISTA, CLIENTE y EMPRESARIO; `RequiereSesion`, módulo 10): solo cambia **la propia** contraseña (el cuerpo no puede apuntar a otra cuenta), así que ampliarla no da al personal edición de teléfonos ni correo. Sin sesión `401`; con la cuenta desactivada `401 sesion_invalida`. Siempre actúan sobre **la cuenta de la sesión**: nunca se acepta un id en el cuerpo.

- **GET** → `{ "persona": { tipoDocumento, numeroDocumento, nombreCompleto, telefono, telefonoAuxiliar, correo }, "usuario": { nombreUsuario, rol, activo, fechaCreacion } }`. No expone el hash.
- **PATCH** `{ telefono, telefonoAuxiliar?, correo? }` → cuenta actualizada. Solo cambia esos tres campos de la persona; documento y nombre son de solo lectura. Mismas reglas que el registro; `422` con `campos` si no cumplen y no se guarda nada.
- **POST contrasena** `{ contrasenaActual, contrasenaNueva }` → `{ "contrasenaCambiada": true }`.
  - `422 contrasena_incorrecta` (`campos.contrasenaActual`) si la actual no coincide.
  - `422 datos_invalidos` (`campos.contrasenaNueva`) si la nueva incumple las reglas, es igual a la actual o al usuario.
  - **Cierra las demás sesiones de la cuenta** (ver más abajo).

## Cambiar la contraseña cierra las otras sesiones
Al ingresar, la sesión guarda una **huella** (`sha256` del hash de la contraseña) que nunca se envía al cliente. `RequiereSesion` la compara en cada petición con la del hash actual de la base. Al cambiar la contraseña, la sesión actual adopta la huella nueva y rota su identificador; **cualquier otra sesión de esa cuenta** (otro navegador o dispositivo) pasa a `401 sesion_invalida`. Una sesión abierta sin huella también se rechaza.

## Decisiones y riesgos para revisar
1. **Reutilizar a una persona existente por su documento (RF04).** Es lo que pide el prototipo, pero tiene una **consecuencia de privacidad**: no se verifica que quien se registra sea el titular del documento. Quien conozca el DNI de alguien que ya envió o recibió una encomienda (y que aún no tiene cuenta) puede crear la cuenta con ese documento y, en cuanto exista «Historial de envíos» (módulo 9), ver sus envíos. Opciones de mitigación, **sin implementar** porque son reglas de negocio: exigir que el teléfono coincida con el guardado, validar el documento contra RENIEC/SUNAT, o confirmar la identidad en agencia. Hasta decidirlo, el riesgo existe.
2. **Enumeración de documentos.** `409 cuenta_existente` confirma que ese documento ya tiene cuenta. Es necesario para avisar al usuario; se orienta a acudir a la agencia.
3. **Contraseña mínima de 8 caracteres.** El prototipo no define una política; es la mínima razonable. Máximo 72 por el límite de bcrypt.
4. **Sin límite de intentos** al cambiar la contraseña ni al registrar (igual que el ingreso, ver `auth.md`): pendiente antes de exponer a Internet.
5. **«Actividad reciente»** de la diapositiva 17 no está: necesita reservas y envíos reales (módulos 4 y 9).
6. **Cuentas del personal** (administrador, transportista, gerente): no tienen «Mi cuenta» en el prototipo y las gestiona el gerente en el módulo 10.
