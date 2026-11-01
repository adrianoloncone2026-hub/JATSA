# Funciones del AISLAMIENTO de pm10 en una base SQL Server descartable. Casi todas son PURAS (sin base de datos, red ni archivos) y las comprueba
# probar-pm10-funciones.ps1 con datos sintéticos. NADA de esto se ejecuta sin autorizaciones separadas del propietario (ver docs/plan-pm10-bd-aislada.md).

$script:PATRON_BD_AISLADA = '^JATSA_pm10_aislada_\d{8}_\d{4}$'
$script:LOGIN_AISLADO = 'jatsa_pm10_aislado'
$script:BASE_HABITUAL = 'JATSA_Encomiendas'
$script:TABLAS_ESPERADAS = @('Persona', 'Sucursal', 'Usuario', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Reserva', 'Envio', 'Bulto', 'Viaje', 'Envio_Viaje', 'Pago')

# El nombre de la base aislada: el patrón exacto y NUNCA algo que se parezca a la base habitual.
function Test-NombreBdAislada([string]$nombre) {
    ($nombre -cmatch $script:PATRON_BD_AISLADA) -and ($nombre -notmatch '(?i)encomiendas') -and ($nombre -ne $script:BASE_HABITUAL)
}

# Resultado de «SELECT DB_NAME(), SUSER_SNAME()» en UNA conexión (CLI o servidor HTTP). Rechaza inequívocamente la base habitual.
function Test-DestinoAislado($bdObtenida, $loginObtenido, [string]$baseEsperada, [string]$loginEsperado = $script:LOGIN_AISLADO) {
    if ([string]::IsNullOrWhiteSpace([string]$bdObtenida)) { return [pscustomobject]@{ Valido = $false; Motivo = 'la conexión no devolvió DB_NAME()' } }
    if ([string]$bdObtenida -match '(?i)^JATSA_Encomiendas$|encomiendas') { return [pscustomobject]@{ Valido = $false; Motivo = "LA CONEXIÓN APUNTA A LA BASE HABITUAL ($bdObtenida)" } }
    if (-not (Test-NombreBdAislada $baseEsperada)) { return [pscustomobject]@{ Valido = $false; Motivo = "el nombre esperado «$baseEsperada» no es una base aislada válida" } }
    if ([string]$bdObtenida -cne $baseEsperada) { return [pscustomobject]@{ Valido = $false; Motivo = "DB_NAME() es «$bdObtenida» y se esperaba «$baseEsperada»" } }
    if ([string]$loginObtenido -cne $loginEsperado) { return [pscustomobject]@{ Valido = $false; Motivo = "el login es «$loginObtenido» y se esperaba «$loginEsperado»" } }
    [pscustomobject]@{ Valido = $true; Motivo = 'conectado a la base aislada con el login aislado' }
}

# Las sondas de los servidores HTTP: una respuesta por puerto esperado, todas con HTTP 200 y todas conectadas a la base aislada.
# $respuestas: objetos { Puerto; Estado; Bd; Login }.
function Get-ResultadoSondas($respuestas, [string[]]$puertosEsperados, [string]$baseEsperada, [string]$loginEsperado = $script:LOGIN_AISLADO) {
    $r = @($respuestas | Where-Object { $null -ne $_ })
    $problemas = @()
    $vistos = @($r | ForEach-Object { [string]$_.Puerto })
    foreach ($p in $puertosEsperados) { if ($vistos -notcontains $p) { $problemas += "el puerto $p no respondió a la sonda" } }
    foreach ($x in $r) {
        if ($puertosEsperados -notcontains [string]$x.Puerto) { $problemas += "respuesta de un puerto inesperado ($($x.Puerto))"; continue }
        if ([string]$x.Estado -ne '200') { $problemas += "puerto $($x.Puerto): HTTP $($x.Estado)"; continue }
        $d = Test-DestinoAislado $x.Bd $x.Login $baseEsperada $loginEsperado
        if (-not $d.Valido) { $problemas += "puerto $($x.Puerto): $($d.Motivo)" }
    }
    if (@($vistos | Select-Object -Unique).Count -ne $vistos.Count) { $problemas += 'hay puertos repetidos entre las respuestas' }
    [pscustomobject]@{ Valido = ($problemas.Count -eq 0); Motivo = ($problemas -join ' | ') }
}

# Un proceso PHP de la prueba debe ejecutarse DESDE la copia de trabajo y nunca desde el backend habitual.
function Test-LineaDeComandosEnCopia([string]$lineaDeComandos, [string]$rutaCopia, [string[]]$rutasProhibidas = @()) {
    if ([string]::IsNullOrWhiteSpace($lineaDeComandos) -or [string]::IsNullOrWhiteSpace($rutaCopia)) { return $false }
    $l = $lineaDeComandos.ToLowerInvariant().Replace('/', '\')
    $copia = $rutaCopia.TrimEnd('\').ToLowerInvariant()
    if (-not $l.Contains($copia)) { return $false }
    foreach ($prohibida in @($rutasProhibidas | Where-Object { $_ })) {
        $p = $prohibida.TrimEnd('\').ToLowerInvariant()
        # La ruta de la copia vive fuera del repositorio; si alguna ruta prohibida aparece en la línea, el proceso toca el backend habitual.
        if ($l.Contains($p) -and -not $copia.Contains($p)) { return $false }
    }
    $true
}

# ── Configuración independiente ──────────────────────────────────────────────────────────────────────────────────────────────────
# Contenido del .env de la COPIA: todas las claves de conexión definidas explícitamente (si faltara alguna, Env caería a las variables del proceso).
function New-ContenidoEnvAislado([string]$servidor, [string]$puerto, [string]$base, [string]$usuario, [string]$clave) {
    foreach ($valor in @($servidor, $puerto, $base, $usuario, $clave)) { if ($valor -match "[\r\n]") { throw 'un valor del .env contiene saltos de línea' } }
    if (-not (Test-NombreBdAislada $base)) { throw "«$base» no es un nombre de base aislada válido" }
    if ($usuario -cne $script:LOGIN_AISLADO) { throw "el login debe ser $($script:LOGIN_AISLADO)" }
    if ([string]::IsNullOrEmpty($clave) -or $clave.Length -lt 16) { throw 'falta la contraseña del login aislado (mínimo 16 caracteres)' }
    @(
        '# Generado por la preparación de la base aislada pm10. COPIA DE TRABAJO TEMPORAL: no es el .env habitual y se borra con la copia.',
        'APP_ENV=prueba', 'APP_TZ=America/Lima', 'CORS_ORIGINS=http://localhost:5173', 'SESSION_HORAS=8', 'SESSION_DIAS_RECORDAR=14', 'SESSION_SECURE=false',
        "DB_HOST=$servidor", "DB_PORT=$puerto", "DB_NAME=$base", "DB_USER=$usuario", "DB_PASSWORD=$clave", 'DB_ENCRYPT=false', 'DB_TRUST_CERT=true'
    ) -join "`n"
}

# Lee las claves CLAVE=valor de un .env (para comprobar el de la copia). Nunca se imprime la contraseña.
function Get-ValoresEnv([string]$texto) {
    $v = @{}; $repetidas = @()
    foreach ($linea in ($texto -split "`r?`n")) {
        $l = $linea.Trim()
        if ($l -eq '' -or $l.StartsWith('#') -or -not $l.Contains('=')) { continue }
        $i = $l.IndexOf('='); $k = $l.Substring(0, $i).Trim(); $val = $l.Substring($i + 1).Trim()
        if ($v.ContainsKey($k)) { $repetidas += $k }
        $v[$k] = $val
    }
    [pscustomobject]@{ Valores = $v; Repetidas = $repetidas }
}

function Test-EnvDeCopia([string]$texto, [string]$base, [string]$login = $script:LOGIN_AISLADO) {
    $e = Get-ValoresEnv $texto; $v = $e.Valores; $problemas = @()
    foreach ($k in 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD') { if (-not $v.ContainsKey($k)) { $problemas += "falta $k (sin ella, Env usaría las variables del proceso)" } }
    if ($e.Repetidas.Count -gt 0) { $problemas += "claves repetidas: $($e.Repetidas -join ', ')" }
    if ($v.ContainsKey('DB_NAME') -and $v['DB_NAME'] -cne $base) { $problemas += "DB_NAME es «$($v['DB_NAME'])» y debe ser «$base»" }
    if ($v.ContainsKey('DB_NAME') -and $v['DB_NAME'] -match '(?i)encomiendas') { $problemas += 'DB_NAME apunta a la base habitual' }
    if ($v.ContainsKey('DB_USER') -and $v['DB_USER'] -cne $login) { $problemas += "DB_USER es «$($v['DB_USER'])» y debe ser «$login»" }
    if ($v.ContainsKey('DB_PASSWORD') -and [string]::IsNullOrEmpty($v['DB_PASSWORD'])) { $problemas += 'DB_PASSWORD está vacía' }
    [pscustomobject]@{ Valido = ($problemas.Count -eq 0); Motivo = ($problemas -join ' | ') }
}

# Compara dos inventarios { rutaRelativa = sha256 }: la copia de trabajo debe tener EXACTAMENTE el mismo código que el repositorio.
function Get-DiferenciasHashes([hashtable]$origen, [hashtable]$copia) {
    $d = @()
    foreach ($k in $origen.Keys) {
        if (-not $copia.ContainsKey($k)) { $d += "falta en la copia: $k" } elseif ($copia[$k] -cne $origen[$k]) { $d += "difiere: $k" }
    }
    foreach ($k in $copia.Keys) { if (-not $origen.ContainsKey($k)) { $d += "sobra en la copia: $k" } }
    $d
}

# ── Estructura derivada del script original (el original NO se modifica ni se ejecuta) ─────────────────────────────────────────────
# Toma la parte de estructura (los CREATE TABLE) desde justo después de «USE JATSA_Encomiendas» hasta el último «GO» anterior al primer INSERT,
# sin «CREATE DATABASE», sin «USE» original y sin datos de ejemplo, y antepone el USE de la base aislada.
function ConvertTo-DdlDerivado([string]$original, [string]$base) {
    if (-not (Test-NombreBdAislada $base)) { throw "«$base» no es un nombre de base aislada válido" }
    $lineas = $original -split "`r?`n"
    $iUse = -1; $iInsert = -1
    for ($i = 0; $i -lt $lineas.Count; $i++) {
        if ($iUse -lt 0 -and $lineas[$i] -match '^\s*USE\s+JATSA_Encomiendas\s*;?\s*$') { $iUse = $i }
        if ($iInsert -lt 0 -and $lineas[$i] -match '^\s*INSERT\s+INTO\b') { $iInsert = $i; break }
    }
    if ($iUse -lt 0) { throw 'no se encontró «USE JATSA_Encomiendas» en el script original' }
    if ($iInsert -lt 0) { throw 'no se encontró el primer INSERT en el script original' }
    $ultimoGo = -1
    for ($i = $iInsert - 1; $i -gt $iUse; $i--) { if ($lineas[$i] -match '^\s*GO\s*$') { $ultimoGo = $i; break } }
    if ($ultimoGo -lt 0) { throw 'no se encontró el GO que cierra la estructura' }
    $cuerpo = $lineas[($iUse + 1)..$ultimoGo]
    (@("USE [$base];", 'GO') + $cuerpo) -join "`n"
}

# Lotes separados por «GO» (las líneas que son solo GO); se descartan los lotes vacíos.
function Split-LotesGo([string]$texto) {
    $lotes = @(); $actual = New-Object System.Collections.Generic.List[string]
    foreach ($linea in ($texto -split "`r?`n")) {
        if ($linea -match '^\s*GO\s*$') { if (($actual -join '').Trim() -ne '') { $lotes += ($actual -join "`n") }; $actual = New-Object System.Collections.Generic.List[string] }
        else { $actual.Add($linea) }
    }
    if (($actual -join '').Trim() -ne '') { $lotes += ($actual -join "`n") }
    $lotes
}

# Lista de problemas de la estructura derivada (vacía = se puede aplicar a la base aislada).
function Test-DdlDerivado([string]$texto, [string]$base) {
    $p = @()
    $sinComentarios = [regex]::Replace([regex]::Replace($texto, '(?s)/\*.*?\*/', ''), '(?m)--.*$', '')
    if ($texto -match '(?i)JATSA_Encomiendas') { $p += 'menciona JATSA_Encomiendas' }
    if ($sinComentarios -match '(?i)\bINSERT\b') { $p += 'contiene INSERT (datos de ejemplo)' }
    if ($sinComentarios -match '(?i)\b(CREATE|ALTER|DROP)\s+(DATABASE|LOGIN|USER)\b') { $p += 'contiene sentencias sobre bases, logins o usuarios' }
    if ($sinComentarios -match '(?i)\b(DROP|TRUNCATE|DBCC|EXEC|EXECUTE|UPDATE|DELETE|MERGE)\b') { $p += 'contiene sentencias no estructurales (DROP/TRUNCATE/DBCC/EXEC/UPDATE/DELETE/MERGE)' }
    if ($sinComentarios -match '(?i)\b\w+\.\w+\.\w+\b\s*\(' -or $sinComentarios -match '(?i)\[?\w+\]?\.dbo\.') { $p += 'referencia otra base con un nombre de tres partes' }
    $usos = @([regex]::Matches($sinComentarios, '(?im)^\s*USE\s+\[?([^\];\s]+)\]?\s*;?\s*$') | ForEach-Object { $_.Groups[1].Value })
    if ($usos.Count -ne 1 -or $usos[0] -cne $base) { $p += "debe tener exactamente un USE y dirigido a la base aislada («$base»); tiene: $($usos -join ',')" }
    $tablas = @([regex]::Matches($sinComentarios, '(?im)^\s*CREATE\s+TABLE\s+\[?(\w+)\]?') | ForEach-Object { $_.Groups[1].Value })
    if ($tablas.Count -ne 12) { $p += "debe tener 12 CREATE TABLE y tiene $($tablas.Count)" }
    $faltan = @($script:TABLAS_ESPERADAS | Where-Object { $tablas -notcontains $_ }); if ($faltan.Count -gt 0) { $p += "faltan las tablas: $($faltan -join ', ')" }
    $sobran = @($tablas | Where-Object { $script:TABLAS_ESPERADAS -notcontains $_ }); if ($sobran.Count -gt 0) { $p += "tablas inesperadas: $($sobran -join ', ')" }
    if (@(Split-LotesGo $texto).Count -lt 2) { $p += 'no hay lotes separados por GO' }
    $p
}

# ── Sesiones y transacciones SQL Server de la prueba, antes de limpiar ───────────────────────────────────────────────────────────
# WaitForExit acredita que terminó el PROCESO de Windows, no que SQL Server haya terminado el rollback de sus transacciones. Se consulta al servidor
# por las sesiones del login aislado distintas de la actual. $puedeVer = HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW SERVER STATE'); sin ese permiso las
# vistas solo muestran la sesión propia y la ausencia de filas NO prueba nada. Ante la duda: no se limpia.
# $filas: { session_id; status; transacciones; solicitudes }.
function Get-EvaluacionSesionesSql($puedeVer, $filas) {
    $motivos = @()
    if ("$puedeVer" -ne '1') { return [pscustomobject]@{ Segura = $false; Motivos = @('no se puede verificar: el login aislado no tiene VIEW SERVER STATE y las vistas solo mostrarían su propia sesión') } }
    foreach ($f in @($filas | Where-Object { $null -ne $_ })) {
        $detalle = "sesión $($f.session_id) [$($f.status)]"
        if ([int]$f.transacciones -gt 0) { $motivos += "$detalle con $($f.transacciones) transacción(es) abierta(s)" }
        elseif ([int]$f.solicitudes -gt 0) { $motivos += "$detalle con $($f.solicitudes) solicitud(es) en ejecución" }
        else { $motivos += "$detalle sigue conectada aunque todos los procesos PHP terminaron" }
    }
    [pscustomobject]@{ Segura = ($motivos.Count -eq 0); Motivos = $motivos }
}