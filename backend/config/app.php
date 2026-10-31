<?php
declare(strict_types=1);

use Jatsa\Core\Env;

// Configuración de la aplicación. Los valores salen de backend/.env (no versionado);
// aquí solo hay valores por defecto no sensibles. No escribir credenciales en este archivo.
$lista = static fn (string $texto): array => array_values(array_filter(array_map('trim', explode(',', $texto))));
$logico = static fn (?string $valor): bool => filter_var($valor, FILTER_VALIDATE_BOOLEAN);

return [
    'app' => [
        'entorno' => Env::obtener('APP_ENV', 'local'),
        'zona_horaria' => Env::obtener('APP_TZ', 'America/Lima'),
        'version' => '0.1.0',
    ],
    'cors' => [
        'origenes' => $lista(Env::obtener('CORS_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173') ?? ''),
    ],
    'db' => [
        'host' => Env::obtener('DB_HOST', 'localhost'),
        'puerto' => Env::obtener('DB_PORT', ''),
        'nombre' => Env::obtener('DB_NAME', ''),
        'usuario' => Env::obtener('DB_USER', ''),
        'clave' => Env::obtener('DB_PASSWORD', ''),
        'cifrar' => $logico(Env::obtener('DB_ENCRYPT', 'false')),
        'confiar_certificado' => $logico(Env::obtener('DB_TRUST_CERT', 'true')),
    ],
];
