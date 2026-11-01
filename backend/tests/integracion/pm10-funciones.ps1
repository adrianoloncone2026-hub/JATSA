# Funciones puras (sin base de datos ni red) del validador real pm10. Las usa validar-pm10.ps1 y las comprueba probar-pm10-funciones.ps1
# con datos sintéticos. Aquí vive la regla que separa DOS cosas distintas:
#   1) los DATOS ORIGINALES (filas, checksum y hash de cada fila de las 12 tablas): deben quedar IDÉNTICOS tras la limpieza;
#   2) los CONTADORES IDENTITY (IDENT_CURRENT): borrar filas no los restaura y una carrera fallida también puede consumir valores,
#      así que NO se promete que coincidan: solo se informa cuánto avanzaron, se exige que no retrocedan y se necesita aceptar de antemano
#      su avance irreversible.

# Tablas con IDENTITY que este módulo puede avanzar (las demás deben quedar con el mismo contador).
$script:TABLAS_CON_AVANCE_ESPERADO = @('Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje')

# Orden de borrado respetando las claves foráneas: hijos antes que padres.
$script:ORDEN_LIMPIEZA = @('Envio_Viaje', 'Viaje', 'Telefono_Sucursal', 'Ruta', 'Usuario', 'Persona', 'Vehiculo', 'Sucursal')

function Get-OrdenLimpieza { $script:ORDEN_LIMPIEZA }

# Compara las filas, el checksum y el hash de cada fila de CADA tabla (sin mirar IDENT_CURRENT).
# Devuelve un objeto por tabla: Tabla, FilasAntes, FilasDespues, Identica.
function Get-ComparacionOriginales($antes, $despues) {
    foreach ($nombre in $antes.tablas.PSObject.Properties.Name) {
        $a = $antes.tablas.$nombre; $d = $despues.tablas.$nombre
        $identica = ($null -ne $d) -and ($a.filas -eq $d.filas) -and ([string]$a.checksum -eq [string]$d.checksum) -and ((@($a.hashes) -join ',') -eq (@($d.hashes) -join ','))
        [pscustomobject]@{ Tabla = $nombre; FilasAntes = $a.filas; FilasDespues = $(if ($null -ne $d) { $d.filas } else { $null }); Identica = [bool]$identica }
    }
}

# Clasifica el IDENT_CURRENT de cada tabla: SinCambio, Avanzo (esperado e informado) o RETROCEDIO (fallo: nadie debe reiniciar contadores).
# Una tabla fuera de TABLAS_CON_AVANCE_ESPERADO que avance se marca Inesperado: algo escribió donde no debía.
function Get-AvanceIdentity($antes, $despues) {
    foreach ($nombre in $antes.tablas.PSObject.Properties.Name) {
        $a = [long]($antes.tablas.$nombre.ident); $d = [long]($despues.tablas.$nombre.ident)
        $estado = if ($d -lt $a) { 'RETROCEDIO' } elseif ($d -eq $a) { 'SinCambio' } elseif ($script:TABLAS_CON_AVANCE_ESPERADO -contains $nombre) { 'Avanzo' } else { 'Inesperado' }
        [pscustomobject]@{ Tabla = $nombre; Antes = $a; Despues = $d; Avance = ($d - $a); Estado = $estado }
    }
}

# Validación del manifiesto (lo único que el script PHP puede borrar). Devuelve la lista de problemas (vacía = válido).
# idsAntes trae, por tabla, TODOS los ids de la instantánea inicial: puede ser una lista vacía (tabla inicialmente vacía), pero debe existir.
function Test-ManifiestoPm10($m) {
    $problemas = @()
    $reglas = @{ sucursales = '^PMX9\d{2}$'; vehiculos = '^PM1-9\d{2}$'; documentos = '^(9000010\d|2000000010\d)$'; usuarios = '^pm10_[a-z0-9]{3,20}$'; viajes = '^VJ\d{10}$' }
    foreach ($clave in $reglas.Keys) {
        foreach ($valor in @($m[$clave])) {
            if ($null -ne $valor -and [string]$valor -notmatch $reglas[$clave]) { $problemas += "$clave contiene un valor inesperado: $valor" }
        }
    }
    foreach ($t in @('Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje', 'Envio_Viaje')) {
        if ($null -eq $m.idsAntes -or -not $m.idsAntes.ContainsKey($t)) { $problemas += "falta la lista de ids iniciales de $t (puede estar vacía, pero debe existir)"; continue }
        $ids = @($m.idsAntes[$t])
        if (@($ids | Where-Object { $_ -isnot [int] -and $_ -isnot [long] }).Count -gt 0) { $problemas += "idsAntes de $t contiene valores que no son enteros" }
        if (@($ids | Select-Object -Unique).Count -ne $ids.Count) { $problemas += "idsAntes de $t tiene ids repetidos" }
    }
    $problemas
}

# Una copia INDEPENDIENTE de una tabla hash con algunas claves sustituidas. (`$a + $b` lanza una excepción si $b repite una clave de $a:
# así fallaba la prueba «origen = destino», que quería sustituir `destino`.)
function Get-ConCambios([hashtable]$base, [hashtable]$cambios) {
    $copia = @{}
    foreach ($k in $base.Keys) { $copia[$k] = $base[$k] }
    foreach ($k in $cambios.Keys) { $copia[$k] = $cambios[$k] }
    $copia
}

# Distingue los HTTP 500 que son un INTERBLOQUEO real (SQL Server 1205: la petición fue elegida víctima) de los 500 genéricos (un error
# interno cualquiera). La evidencia son las líneas NUEVAS del registro de la aplicación durante la carrera: solo cuentan como interbloqueo
# las que mencionan 1205 o «deadlock». Un 500 sin esa evidencia es un FALLO, no un resultado aceptable.
function Get-Clasificacion500($respuestas, [string[]]$lineasLogNuevas) {
    $n500 = @($respuestas | Where-Object { $_.Estado -eq 500 }).Count
    $evidencias = @($lineasLogNuevas | Where-Object { $_ -match '(?i)\b1205\b|deadlock' }).Count
    $interbloqueos = [Math]::Min($n500, $evidencias)
    [pscustomobject]@{ Total500 = $n500; Interbloqueos = $interbloqueos; Genericos = ($n500 - $interbloqueos) }
}

# Evalúa la carrera «dos cambios de contraseña con la MISMA contraseña anterior». Reglas:
#   - exactamente UNA solicitud confirma (200);
#   - la otra recibe un resultado legítimo: 409 contrasena_modificada (perdió la actualización condicional), 422 contrasena_incorrecta (llegó
#     después del primer commit), 401 sesion_invalida (RequiereSesion ya vio el hash nuevo y cerró su sesión: legítimo, nunca llegó a la CAS)
#     o un 500 SOLO si es un interbloqueo comprobado;
#   - y, sea cual sea el motivo, SOLO sirve la contraseña de quien confirmó: ni las dos ni ninguna.
# Un 401 `no_autenticado` (sin sesión) o cualquier otro código NO es aceptable: no se confunde con el 401 legítimo.
function Get-ResultadoCarreraContrasena($respuestas, [string]$puertoA, [bool]$entraConLaDeA, [bool]$entraConLaDeB, [bool]$perdedor500EsInterbloqueo) {
    $r = @($respuestas)
    $ganadoras = @($r | Where-Object { $_.Estado -eq 200 })
    $perdedoras = @($r | Where-Object { $_.Estado -ne 200 })
    if ($r.Count -ne 2) { return [pscustomobject]@{ Valido = $false; Motivo = 'se esperaban dos respuestas' } }
    if ($ganadoras.Count -ne 1) { return [pscustomobject]@{ Valido = $false; Motivo = "debía confirmar exactamente una solicitud y confirmaron $($ganadoras.Count)" } }
    $p = $perdedoras[0]
    $legitima = (($p.Estado -eq 409 -and $p.Codigo -eq 'contrasena_modificada') -or ($p.Estado -eq 422 -and $p.Codigo -eq 'contrasena_incorrecta') -or ($p.Estado -eq 401 -and $p.Codigo -eq 'sesion_invalida') -or ($p.Estado -eq 500 -and $perdedor500EsInterbloqueo))
    if (-not $legitima) { return [pscustomobject]@{ Valido = $false; Motivo = "la solicitud perdedora respondió $($p.Estado)/$($p.Codigo), que no es un resultado legítimo de perder la carrera" } }
    $ganaA = ([string]$ganadoras[0].Puerto -eq $puertoA)
    $correcto = if ($ganaA) { $entraConLaDeA -and -not $entraConLaDeB } else { $entraConLaDeB -and -not $entraConLaDeA }
    if (-not $correcto) { return [pscustomobject]@{ Valido = $false; Motivo = 'solo debe servir la contraseña de la solicitud que confirmó (y ninguna otra)' } }
    [pscustomobject]@{ Valido = $true; Motivo = 'una confirmó y solo sirve su contraseña' }
}

# ── Control de los trabajos paralelos de las carreras ────────────────────────────────────────────────────────────────────────────
# Cada tarea de una carrera es un trabajo (Start-Job) que lanza UNA petición HTTP. Si un trabajo no termina (timeout) o no entrega su respuesta,
# esa petición PHP puede seguir viva y escribiendo cuando empiece la limpieza: por eso la limpieza automática solo se permite con TODOS los trabajos
# concluidos y con una respuesta por tarea.

# $infos: un objeto por trabajo { Id; Estado (Completed, Running, Failed, Blocked, NotStarted, Stopped…); Respuestas (lo que entregó); Error }.
# Confirmado solo si hay exactamente $esperados trabajos, TODOS en estado Completed, cada uno con EXACTAMENTE una respuesta con un Estado HTTP numérico.
function Get-EvaluacionTrabajos($infos, [int]$esperados) {
    $lista = @($infos | Where-Object { $null -ne $_ })
    $problemas = @()
    if ($lista.Count -ne $esperados) { $problemas += "se esperaban $esperados trabajos y hay $($lista.Count)" }
    $pendientes = @($lista | Where-Object { $_.Estado -in 'Running', 'NotStarted', 'Blocked', 'Suspended', 'Suspending', 'Stopping' })
    $fallidos = @($lista | Where-Object { $_.Estado -in 'Failed', 'Stopped', 'Disconnected' -or $_.Error })
    $sinRespuesta = @($lista | Where-Object { $_.Estado -eq 'Completed' -and -not $_.Error -and (@($_.Respuestas).Count -ne 1 -or $null -eq @($_.Respuestas)[0] -or $null -eq @($_.Respuestas)[0].Estado -or -not ([string]@($_.Respuestas)[0].Estado -match '^\d+$')) })
    if ($pendientes.Count -gt 0) { $problemas += "$($pendientes.Count) trabajo(s) siguen activos tras el límite de espera (ids: $(($pendientes | ForEach-Object { $_.Id }) -join ','))" }
    if ($fallidos.Count -gt 0) { $problemas += "$($fallidos.Count) trabajo(s) fallaron o se detuvieron: $(($fallidos | ForEach-Object { "id $($_.Id) [$($_.Estado)] $($_.Error)" }) -join '; ')" }
    if ($sinRespuesta.Count -gt 0) { $problemas += "$($sinRespuesta.Count) trabajo(s) terminaron sin entregar exactamente una respuesta HTTP válida (ids: $(($sinRespuesta | ForEach-Object { $_.Id }) -join ','))" }
    [pscustomobject]@{ Confirmado = ($problemas.Count -eq 0); Pendientes = $pendientes.Count; Fallidos = $fallidos.Count; SinRespuesta = $sinRespuesta.Count; Motivo = ($problemas -join ' | ') }
}

# ¿Se puede empezar la limpieza automática? Solo si no hubo incidencias en trabajos paralelos, no queda ningún trabajo vivo y todos los procesos PHP
# (servidores de prueba) están confirmados como terminados. Ante cualquier duda: NO, y se reporta para una recuperación controlada con el manifiesto.
function Get-DecisionLimpieza($incidencias, $procesosPhpVivos, $trabajosVivos) {
    $motivos = @()
    foreach ($i in @($incidencias | Where-Object { $_ })) { $motivos += "incidencia en trabajos paralelos: $i" }
    $php = @($procesosPhpVivos | Where-Object { $null -ne $_ })
    $jobs = @($trabajosVivos | Where-Object { $null -ne $_ })
    if ($php.Count -gt 0) { $motivos += "siguen vivos $($php.Count) proceso(s) PHP de prueba (ids: $($php -join ',')): podrían estar atendiendo una petición" }
    if ($jobs.Count -gt 0) { $motivos += "siguen vivos $($jobs.Count) trabajo(s) de PowerShell (ids: $($jobs -join ','))" }
    [pscustomobject]@{ Permitida = ($motivos.Count -eq 0); Motivos = $motivos }
}