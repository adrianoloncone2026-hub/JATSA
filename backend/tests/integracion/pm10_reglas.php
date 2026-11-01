<?php
declare(strict_types=1);

/*
 * Reglas PURAS (sin base de datos) con las que escritura_pm10.php decide si una fila es de la prueba. Se prueban de forma sintética en
 * backend/tests/catalogos.php. Aquí se corrige el criterio anterior «id > IDENT_CURRENT inicial», que NO sirve cuando una tabla con
 * IDENTITY estaba vacía: IDENT_CURRENT devuelve entonces el valor semilla (1) y la primera fila creada puede recibir ese mismo id.
 *
 * El criterio ahora es el CONJUNTO REAL de ids de la instantánea inicial (idsAntes), no un contador:
 *   - una fila es PROPIA solo si su clave está en el manifiesto Y su id NO estaba en la instantánea inicial;
 *   - si una fila con una clave del manifiesto SÍ estaba en la instantánea inicial, es ORIGINAL: se aborta sin borrar nada;
 *   - cualquier id que existe ahora y no estaba antes, y no es propio, es AJENO (otro proceso escribió, o quedó algo sin anotar): se aborta.
 * Ni siquiera esto prueba la autoría ante escrituras externas simultáneas: por eso el ensayo exige uso exclusivo de la base o una base aislada.
 */

/**
 * @param list<int> $idsAntes      todos los ids de la tabla en la instantánea inicial
 * @param list<int> $idsActuales   todos los ids de la tabla ahora
 * @param list<int> $idsPorClave   ids de las filas que hoy coinciden con las claves del manifiesto (códigos, placas, documentos…)
 * @return array{propias: list<int>, originales: list<int>, ajenas: list<int>}
 */
function pm10_clasificarFilas(array $idsAntes, array $idsActuales, array $idsPorClave): array
{
    $antes = array_flip($idsAntes);
    $actuales = array_flip($idsActuales);
    $propias = [];
    $originales = [];
    foreach (array_unique($idsPorClave) as $id) {
        if (isset($antes[$id])) {
            $originales[] = $id; // coincide una clave del manifiesto con una fila que YA existía
        } elseif (isset($actuales[$id])) {
            $propias[] = $id;
        }
    }
    $conocidas = array_flip($propias);
    $ajenas = [];
    foreach ($idsActuales as $id) {
        if (!isset($antes[$id]) && !isset($conocidas[$id])) {
            $ajenas[] = $id;
        }
    }

    sort($propias);
    sort($originales);
    sort($ajenas);

    return ['propias' => $propias, 'originales' => $originales, 'ajenas' => $ajenas];
}

/** Ids enteros positivos y sin repetir, o null si la lista del manifiesto es inválida. @return list<int>|null */
function pm10_idsValidos(mixed $valores): ?array
{
    if (!is_array($valores)) {
        return null;
    }
    $lista = [];
    foreach ($valores as $v) {
        if (!is_int($v) || $v < 1) {
            return null;
        }
        $lista[] = $v;
    }

    return count($lista) === count(array_unique($lista)) ? $lista : null;
}