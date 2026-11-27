/* JATSA - Demostración en SSMS (requiere unidad2_objetos.sql y la base de JATSA seleccionada).
   Ejecutar cada bloque por separado, completo. Los bloques 2 y 3 terminan en ROLLBACK: no dejan cambios. */

/* 1) VISTA: envíos con saldo pendiente (lo que Caja cobra y Entregas exige pagado). */
SELECT TOP 10 codigoSeguimiento, estadoEnvio, cantidadBultos, pesoKg, importe, pagado, importe - pagado AS saldo
FROM dbo.vw_EnvioTotales
WHERE estadoEnvio <> 'ANULADO' AND importe > pagado
ORDER BY saldo DESC;
GO

/* 2) PROCEDIMIENTO: «Marcar salida» de un viaje programado (pantalla Detalle del viaje del transportista). */
BEGIN TRANSACTION;
DECLARE @codigo CHAR(12) =
    (SELECT TOP 1 v.codigoViaje FROM dbo.Viaje AS v
     WHERE v.fechaHoraSalida IS NULL
     ORDER BY (SELECT COUNT(*) FROM dbo.Envio_Viaje AS ev WHERE ev.idViaje = v.idViaje) DESC, v.idViaje);
IF @codigo IS NULL
    PRINT 'No hay viajes programados: programa uno desde Despacho.';
ELSE
BEGIN
    EXEC dbo.usp_Viaje_MarcarSalida @codigoViaje = @codigo;   -- cambiado = 1
    SELECT v.codigoViaje, v.fechaHoraSalida, e.codigoSeguimiento, e.estadoEnvio
    FROM dbo.Viaje AS v
    LEFT JOIN dbo.Envio_Viaje AS ev ON ev.idViaje = v.idViaje
    LEFT JOIN dbo.Envio AS e ON e.idEnvio = ev.idEnvio
    WHERE v.codigoViaje = @codigo;
    EXEC dbo.usp_Viaje_MarcarSalida @codigoViaje = @codigo;   -- repetida: cambiado = 0
END;
ROLLBACK TRANSACTION;
GO

/* 3) DISPARADOR: intentar alterar el monto o borrar un pago se rechaza (errores 51002 y 51001). */
DECLARE @id INT = (SELECT TOP 1 idPago FROM dbo.Pago WHERE estadoPago = 'REGISTRADO' ORDER BY idPago);
BEGIN TRY
    BEGIN TRANSACTION;
    UPDATE dbo.Pago SET monto = monto + 1 WHERE idPago = @id;
    ROLLBACK TRANSACTION;
END TRY
BEGIN CATCH
    SELECT ERROR_NUMBER() AS error, ERROR_MESSAGE() AS reglaProtegida;
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
END CATCH;

BEGIN TRY
    BEGIN TRANSACTION;
    DELETE FROM dbo.Pago WHERE idPago = @id;
    ROLLBACK TRANSACTION;
END TRY
BEGIN CATCH
    SELECT ERROR_NUMBER() AS error, ERROR_MESSAGE() AS reglaProtegida;
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
END CATCH;

/* La operación válida (anular) sí pasa. */
BEGIN TRANSACTION;
UPDATE dbo.Pago SET estadoPago = 'ANULADO' WHERE idPago = @id;
SELECT idPago, monto, estadoPago FROM dbo.Pago WHERE idPago = @id;
ROLLBACK TRANSACTION;
GO
