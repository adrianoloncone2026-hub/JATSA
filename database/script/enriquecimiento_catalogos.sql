/*
  JATSA - Enriquecimiento de catálogos (script APARTE y solo de adición).
  NO modifica docs/JATSA_Envios-SQLQuery1 (1).sql. Se ejecuta DESPUÉS de ese script, sobre la misma base, una sola vez.
  Añade: 8 sucursales, sus teléfonos, rutas con precios y vehículos.
  No toca Persona, Usuario ni personal. Usa las mismas fórmulas de precio del script original.
  Sin ejecutar todavía: requiere autorización expresa para correrlo contra SQL Server.

  Dos sucursales comparten región y provincia: CUS001 y CUS002 (Cusco / Cusco).
  Juliaca (JUL001) y Puno (PUN001) comparten solo región (Puno).
*/
SET XACT_ABORT ON;
BEGIN TRANSACTION;

--8 sucursales nuevas
INSERT INTO Sucursal (codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa) VALUES
    ('CUS001', N'Agencia Cusco Centro',       N'Cusco',       N'Cusco',    N'Cusco',        N'Av. El Sol 815', 1),
    ('CUS002', N'Agencia Cusco San Sebastián', N'Cusco',      N'Cusco',    N'San Sebastián', N'Av. De la Cultura 1020', 1),
    ('PUN001', N'Agencia Puno',               N'Puno',        N'Puno',     N'Puno',         N'Jr. Lima 410', 1),
    ('JUL001', N'Agencia Juliaca',            N'Puno',        N'San Román', N'Juliaca',     N'Jr. Unión 285', 1),
    ('ICA001', N'Agencia Ica',                N'Ica',         N'Ica',      N'Ica',          N'Av. Grau 360', 1),
    ('TRU001', N'Agencia Trujillo',           N'La Libertad', N'Trujillo', N'Trujillo',     N'Av. España 1150', 1),
    ('PIU001', N'Agencia Piura',              N'Piura',       N'Piura',    N'Piura',        N'Av. Sánchez Cerro 740', 1),
    ('HYO001', N'Agencia Huancayo',           N'Junín',       N'Huancayo', N'Huancayo',     N'Calle Real 520', 1);

--Teléfonos (CUS001 y TRU001 tienen dos: atributo multivaluado)
INSERT INTO Telefono_Sucursal (idSucursal, numero)
SELECT s.idSucursal, t.numero
FROM (VALUES
    ('CUS001', '084231140'), ('CUS001', '084231141'),
    ('CUS002', '084274560'),
    ('PUN001', '051351820'),
    ('JUL001', '051321930'),
    ('ICA001', '056231470'),
    ('TRU001', '044241250'), ('TRU001', '044241251'),
    ('PIU001', '073331680'),
    ('HYO001', '064231390')
) AS t (codigo, numero)
JOIN Sucursal s ON s.codigoSucursal = t.codigo;

--Rutas: cada tramo en ambos sentidos y para los tres tipos de envío.
--costo del bulto = precioBase + precioPorKm * distanciaKm (SOBRE 8/0.005, PAQUETE 10/0.020, CARGA 40/0.080, como en el script original)
INSERT INTO Ruta (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde)
SELECT so.idSucursal, sd.idSucursal, tp.tipoEnvio, tp.precioBase, tp.precioPorKm, tr.distanciaKm, tr.tiempoHoras, '2026-11-01'
FROM (VALUES
    ('AQP001', 'CUS001',  520.0, 10), ('CUS001', 'CUS002',    8.0, 1), ('CUS001', 'PUN001', 390.0, 7),
    ('PUN001', 'JUL001',   45.0, 1),  ('JUL001', 'AQP001',  270.0, 5), ('AQP001', 'PUN001', 290.0, 6),
    ('MOQ001', 'PUN001',  380.0, 7),  ('LIM001', 'ICA001',  300.0, 4), ('ICA001', 'AQP001',  660.0, 10),
    ('LIM001', 'TRU001',  560.0, 9),  ('TRU001', 'PIU001',  420.0, 7), ('LIM001', 'HYO001',  300.0, 7)
) AS tr (codigoA, codigoB, distanciaKm, tiempoHoras)
CROSS APPLY (VALUES (tr.codigoA, tr.codigoB), (tr.codigoB, tr.codigoA)) AS sentido (codigoOrigen, codigoDestino)
CROSS APPLY (VALUES
    ('SOBRE',   CAST(8.00 AS DECIMAL(8,2)),  CAST(0.005 AS DECIMAL(6,3))),
    ('PAQUETE', CAST(10.00 AS DECIMAL(8,2)), CAST(0.020 AS DECIMAL(6,3))),
    ('CARGA',   CAST(40.00 AS DECIMAL(8,2)), CAST(0.080 AS DECIMAL(6,3)))
) AS tp (tipoEnvio, precioBase, precioPorKm)
JOIN Sucursal so ON so.codigoSucursal = sentido.codigoOrigen
JOIN Sucursal sd ON sd.codigoSucursal = sentido.codigoDestino;

--Vehículos
INSERT INTO Vehiculo (placa, tipoVehiculo, capacidadKg) VALUES
    ('F4N-318', 'CAMION',    15000.00),
    ('G6P-742', 'FURGON',     5000.00),
    ('H2R-564', 'CAMIONETA',  1200.00),
    ('J9S-427', 'FURGON',     7000.00),
    ('K3T-891', 'CAMIONETA',  1800.00),
    ('L5V-236', 'MOTO',        120.00);

COMMIT TRANSACTION;
GO
