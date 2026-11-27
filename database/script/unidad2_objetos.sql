/*
  JATSA - Unidad 2: vista, procedimientos almacenados y disparador.
  Script APARTE: no modifica docs/JATSA_Envios-SQLQuery1 (1).sql ni crea/altera tablas ni datos.
  Se ejecuta DESPUÉS del script original, con la base de JATSA seleccionada. Es idempotente (CREATE OR ALTER,
  SQL Server 2016 SP1 o superior). El backend lo necesita: sin estos objetos fallan pagos, entregas, viajes,
  rastreo, historial, despacho y exportaciones.
  Para revertir: unidad2_revertir.sql (y volver a la versión anterior de los repositorios PHP).
*/
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* VISTA: una fila por envío con sus totales. El importe sale de Bulto y lo pagado solo de pagos REGISTRADO.
   Usada por Pagos, Entregas, Envios (historial), Publico (rastreo), Despacho, Viajes y Reportes (exportaciones).
   Son subconsultas separadas a propósito: unir Bulto y Pago en un mismo JOIN repetiría importes. */
CREATE OR ALTER VIEW dbo.vw_EnvioTotales
AS
SELECT e.idEnvio,
       e.codigoSeguimiento,
       e.estadoEnvio,
       (SELECT COUNT(*) FROM dbo.Bulto AS b WHERE b.idEnvio = e.idEnvio) AS cantidadBultos,
       (SELECT COALESCE(SUM(b.pesoKg), 0) FROM dbo.Bulto AS b WHERE b.idEnvio = e.idEnvio) AS pesoKg,
       (SELECT COALESCE(SUM(b.costo), 0) FROM dbo.Bulto AS b WHERE b.idEnvio = e.idEnvio) AS importe,
       (SELECT COALESCE(SUM(p.monto), 0) FROM dbo.Pago AS p WHERE p.idEnvio = e.idEnvio AND p.estadoPago = 'REGISTRADO') AS pagado
FROM dbo.Envio AS e;
GO

/* PROCEDIMIENTO: salida de un viaje. Marca Viaje y pasa su carga REGISTRADO -> EN_TRANSITO, todo o nada.
   Devuelve una fila: cambiado (1/0) y enviosActualizados. cambiado = 0 si el viaje no existe o ya salió.
   Quién puede hacerlo y si el vehículo o el transportista están ocupados lo decide el backend (ViajeService). */
CREATE OR ALTER PROCEDURE dbo.usp_Viaje_MarcarSalida
    @codigoViaje CHAR(12)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @idViaje INT, @cambiado INT = 0, @envios INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @idViaje = idViaje FROM dbo.Viaje WITH (UPDLOCK, HOLDLOCK) WHERE codigoViaje = @codigoViaje;

        UPDATE dbo.Viaje SET fechaHoraSalida = SYSDATETIME() WHERE idViaje = @idViaje AND fechaHoraSalida IS NULL;
        SET @cambiado = @@ROWCOUNT;

        IF @cambiado = 1
        BEGIN
            UPDATE dbo.Envio SET estadoEnvio = 'EN_TRANSITO'
            WHERE estadoEnvio = 'REGISTRADO' AND idEnvio IN (SELECT idEnvio FROM dbo.Envio_Viaje WHERE idViaje = @idViaje);
            SET @envios = @@ROWCOUNT;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @cambiado AS cambiado, @envios AS enviosActualizados;
END;
GO

/* PROCEDIMIENTO: llegada de un viaje. Marca Viaje y pasa a EN_DESTINO solo la carga cuyo destino final es el del viaje
   (la que hace transbordo sigue EN_TRANSITO). cambiado = 0 si el viaje no existe, no salió, ya llegó o salió en este mismo segundo. */
CREATE OR ALTER PROCEDURE dbo.usp_Viaje_MarcarLlegada
    @codigoViaje CHAR(12)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @idViaje INT, @idDestino INT, @cambiado INT = 0, @envios INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @idViaje = idViaje, @idDestino = idSucursalDestino
        FROM dbo.Viaje WITH (UPDLOCK, HOLDLOCK) WHERE codigoViaje = @codigoViaje;

        UPDATE dbo.Viaje SET fechaHoraLlegada = SYSDATETIME()
        WHERE idViaje = @idViaje AND fechaHoraSalida IS NOT NULL AND fechaHoraLlegada IS NULL
          AND fechaHoraSalida < CAST(SYSDATETIME() AS DATETIME2(0));
        SET @cambiado = @@ROWCOUNT;

        IF @cambiado = 1
        BEGIN
            UPDATE e SET e.estadoEnvio = 'EN_DESTINO'
            FROM dbo.Envio AS e
            INNER JOIN dbo.Envio_Viaje AS ev ON ev.idEnvio = e.idEnvio
            INNER JOIN dbo.Reserva AS r ON r.idReserva = e.idReserva
            WHERE ev.idViaje = @idViaje AND e.estadoEnvio = 'EN_TRANSITO' AND r.idSucursalDestino = @idDestino;
            SET @envios = @@ROWCOUNT;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @cambiado AS cambiado, @envios AS enviosActualizados;
END;
GO

/* DISPARADOR: un pago es un registro de caja. No se elimina y lo único que admite cambiar es REGISTRADO -> ANULADO.
   Protege frente a cambios hechos fuera del backend (SSMS u otros clientes); los CHECK no pueden comparar con el valor anterior.
   Trabaja por conjuntos (varias filas) y solo valida: no escribe nada, así que no hay recursividad ni efectos secundarios.
   Un solo registro inválido deshace toda la instrucción. */
CREATE OR ALTER TRIGGER dbo.trg_Pago_ProtegerRegistro
ON dbo.Pago
AFTER UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM deleted) RETURN;

    IF NOT EXISTS (SELECT 1 FROM inserted)
        THROW 51001, N'Un pago no se elimina: debe anularse (estadoPago = ANULADO).', 1;

    IF EXISTS (
        SELECT 1
        FROM deleted AS d
        LEFT JOIN inserted AS i ON i.idPago = d.idPago
        WHERE i.idPago IS NULL
           OR i.idEnvio <> d.idEnvio
           OR i.idSucursal <> d.idSucursal
           OR i.fechaHoraPago <> d.fechaHoraPago
           OR i.monto <> d.monto
           OR i.metodoPago <> d.metodoPago
           OR i.pagadoPor <> d.pagadoPor
           OR (i.estadoPago <> d.estadoPago AND NOT (d.estadoPago = 'REGISTRADO' AND i.estadoPago = 'ANULADO'))
    )
        THROW 51002, N'Un pago registrado no se modifica: solo puede pasar de REGISTRADO a ANULADO.', 1;
END;
GO
