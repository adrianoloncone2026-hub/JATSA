/* JATSA - Revierte unidad2_objetos.sql. Solo elimina esos cuatro objetos; no toca tablas ni datos.
   El backend actual los usa: revertir exige volver también a la versión anterior de los repositorios PHP. */
DROP TRIGGER IF EXISTS dbo.trg_Pago_ProtegerRegistro;
DROP PROCEDURE IF EXISTS dbo.usp_Viaje_MarcarSalida;
DROP PROCEDURE IF EXISTS dbo.usp_Viaje_MarcarLlegada;
DROP VIEW IF EXISTS dbo.vw_EnvioTotales;
GO
