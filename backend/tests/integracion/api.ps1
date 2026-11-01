# Asistente HTTP para las pruebas reales pm4. No imprime ni guarda contraseñas.
$script:Base = 'http://localhost:8000'
$script:Cookie = ''
$ErrorActionPreference = 'Stop'

function Llamar {
    param([string]$Metodo, [string]$Ruta, $Cuerpo = $null, [string]$Puerto = '8000', [string]$Galleta = $script:Cookie)
    $cabeceras = @{ 'X-Requested-With' = 'JATSA'; 'Accept' = 'application/json' }
    $parametros = @{ Uri = "http://localhost:$Puerto$Ruta"; Method = $Metodo; Headers = $cabeceras; UseBasicParsing = $true }
    if ($Galleta) {
        # PowerShell 5.1 descarta una cabecera Cookie manual: la cookie va en un WebRequestSession.
        $sesion = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $sesion.Cookies.Add((New-Object System.Net.Cookie('JATSA_SESION', ($Galleta -replace '^JATSA_SESION=', ''), '/', 'localhost')))
        $parametros['WebSession'] = $sesion
    }
    if ($null -ne $Cuerpo) {
        $parametros['Body'] = [System.Text.Encoding]::UTF8.GetBytes(($Cuerpo | ConvertTo-Json -Depth 6 -Compress))
        $parametros['ContentType'] = 'application/json; charset=utf-8'
    }
    try {
        $r = Invoke-WebRequest @parametros
        $estado = [int]$r.StatusCode; $texto = $r.Content; $cab = $r.Headers
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
        $estado = [int]$resp.StatusCode
        $lector = New-Object System.IO.StreamReader($resp.GetResponseStream(), [System.Text.Encoding]::UTF8)
        $texto = $lector.ReadToEnd(); $cab = $resp.Headers
    }
    $json = $null
    if ($texto) { try { $json = $texto | ConvertFrom-Json } catch { } }
    [pscustomobject]@{ Estado = $estado; Json = $json; Texto = $texto; Cabeceras = $cab }
}

function GalletaDe($respuesta) {
    $sc = $respuesta.Cabeceras['Set-Cookie']
    if ($sc -is [array]) { $sc = $sc -join ',' }
    # PHP puede enviar varias cabeceras Set-Cookie al regenerar el id: vale la ÚLTIMA.
    $todas = [regex]::Matches([string]$sc, 'JATSA_SESION=[^;,\s]+')
    if ($todas.Count -gt 0) { return $todas[$todas.Count - 1].Value }
    return ''
}
