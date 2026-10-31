<?php
declare(strict_types=1);

/**
 * Vence las reservas PENDIENTES que cumplieron el plazo de vigencia (72 horas desde fechaHoraReserva).
 *
 *   php backend/bin/vencer-reservas.php                       Simulación: solo LISTA lo que vencería (no escribe).
 *   php backend/bin/vencer-reservas.php --ejecutar            Persiste el vencimiento (UPDATE).
 *   php backend/bin/vencer-reservas.php --referencia="2026-11-03 09:15:00"
 *                                                             Usa ese instante en lugar del reloj de SQL Server
 *                                                             (para comprobaciones; combinable con --ejecutar).
 *
 * Por seguridad NO escribe nada salvo que se indique --ejecutar. No lo programa ni lo activa ninguna parte del
 * sistema: quien administre el servidor debe programarlo (ver docs/api/reservas.md). Solo funciona por línea de comandos.
 *
 * Códigos de salida: 0 correcto · 1 error · 2 argumentos no válidos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use Jatsa\Core\Config;
use Jatsa\Modules\Reservas\RepositorioReservas;
use Jatsa\Modules\Reservas\VencimientoService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Registro;

$opciones = getopt('', ['ejecutar', 'referencia:', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo "Uso: php backend/bin/vencer-reservas.php [--ejecutar] [--referencia=\"AAAA-MM-DD HH:MM:SS\"]\n";
    echo "Sin --ejecutar solo simula: lista las reservas que vencerían, sin escribir nada.\n";
    exit(0);
}

$referencia = null;
if (isset($opciones['referencia'])) {
    $referencia = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $opciones['referencia']) ?: null;
    if ($referencia === null || $referencia->format('Y-m-d H:i:s') !== $opciones['referencia']) {
        fwrite(STDERR, "La referencia debe tener el formato AAAA-MM-DD HH:MM:SS.\n");
        exit(2);
    }
}

Config::cargar($raiz);
date_default_timezone_set((string) Config::obtener('app.zona_horaria', 'America/Lima'));
$servicio = new VencimientoService();
$ejecutar = isset($opciones['ejecutar']);

try {
    $vencibles = $servicio->vencibles($referencia);
    printf(
        "Plazo de vigencia: %d horas. Instante de comparación: %s.\n",
        RepositorioReservas::HORAS_DE_VIGENCIA,
        $referencia?->format('Y-m-d H:i:s') ?? 'reloj de SQL Server',
    );
    printf("Reservas pendientes que cumplieron el plazo: %d\n", count($vencibles));
    foreach ($vencibles as $reserva) {
        printf("  %s  (reservada el %s)\n", $reserva['codigo'], $reserva['fechaHora']);
    }

    if (!$ejecutar) {
        echo "SIMULACIÓN: no se escribió nada. Use --ejecutar para persistir el vencimiento.\n";
        exit(0);
    }

    // El UPDATE vuelve a condicionar por estado y plazo, así que el número real puede ser menor si una cancelación se adelantó.
    $vencidas = $servicio->vencer($referencia);
    printf("EJECUTADO: %d reserva(s) pasaron a VENCIDA.\n", $vencidas);
    exit(0);
} catch (HttpException $excepcion) {
    fwrite(STDERR, 'Error: ' . $excepcion->getMessage() . "\n");
    Registro::error('vencer-reservas: ' . $excepcion->getMessage(), $excepcion);
    exit(1);
} catch (Throwable $excepcion) {
    fwrite(STDERR, "Error inesperado: no se pudo completar el vencimiento. Revise backend/storage/logs.\n");
    Registro::error('vencer-reservas', $excepcion);
    exit(1);
}
