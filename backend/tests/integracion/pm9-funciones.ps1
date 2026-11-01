# Funciones puras (sin base de datos ni red) del validador pm9: reglas de horarios frecuentes y de «programado».
# Las usa validar-pm9-lectura.ps1 y las comprueba probar-pm9-funciones.ps1 con datos sintéticos.

# $viajes: filas { o, d, salida 'AAAA-MM-DD HH:mm:ss' } YA limitadas a los últimos 10 con salida de cada par.
# Devuelve un hashtable 'ORIGEN-DESTINO' -> string[] con las hasta 3 horas HH:00 más frecuentes (empate: la más temprana), en orden de reloj.
# Una ruta SIN viajes con salida NO tiene clave: no se inventa una entrada nula.
function Get-HorariosEsperados($viajes) {
    $resultado = @{}
    foreach ($g in ($viajes | Where-Object { $_ -and $_.salida } | Group-Object { "$($_.o)-$($_.d)" })) {
        $conteo = $g.Group | Group-Object { $_.salida.Substring(11, 2) + ':00' }
        $resultado[$g.Name] = [string[]]@($conteo | Sort-Object @{ e = { $_.Count }; Descending = $true }, @{ e = { $_.Name }; Descending = $false } | Select-Object -First 3 | ForEach-Object { $_.Name } | Sort-Object)
    }
    $resultado
}

# Esperado de UNA ruta: horarios (siempre un string[], vacío si la clave no existe: nunca @($null)) y «programado»
# (sin historial de salidas Y con al menos un viaje sin salida en esa ruta).
function Get-EsperadoDeRuta($id, $horariosEsperados, $programadosBd) {
    [string[]]$horarios = @()
    if ($horariosEsperados.ContainsKey($id) -and $null -ne $horariosEsperados[$id]) { $horarios = [string[]]@($horariosEsperados[$id] | Where-Object { $_ }) }
    $programados = 0
    if ($programadosBd.ContainsKey($id)) { $programados = [int]$programadosBd[$id] }
    [pscustomobject]@{ Horarios = $horarios; Cantidad = $horarios.Length; Programado = (($horarios.Length -eq 0) -and ($programados -gt 0)) }
}