--1. Crear Base de datos UwU
IF DB_ID('JATSA_Encomiendas') IS NULL CREATE DATABASE JATSA_Encomiendas;
GO

USE JATSA_Encomiendas;
GO

-- 2. Creación de las tablas •⩊•

--Tabla Persona
CREATE TABLE Persona (
    idPersona INT IDENTITY(1,1) NOT NULL,
    tipoDocumento VARCHAR(3) NOT NULL,
    numeroDocumento VARCHAR(12) NOT NULL,
    nombreCompleto NVARCHAR(150) NOT NULL,
    telefono CHAR(9) NOT NULL,
    telefonoAuxiliar CHAR(9) NULL,
    correo VARCHAR(120) NULL,
    fechaHoraRegistro DATETIME2(0) NOT NULL
        CONSTRAINT DF_PERSONA_fechaHoraRegistro DEFAULT SYSDATETIME(),

    CONSTRAINT PK_PERSONA PRIMARY KEY (idPersona), --el mismo documento no se repite 
    CONSTRAINT UQ_PERSONA_DOCUMENTO UNIQUE (tipoDocumento, numeroDocumento),
    CONSTRAINT CK_PERSONA_TIPO CHECK (tipoDocumento IN ('DNI','CE','RUC','PAS')), 
    --DNI: 8 dígitos; RUC: 11 dígitos
    CONSTRAINT CK_PERSONA_NUMDOC CHECK (
           (tipoDocumento = 'DNI' AND numeroDocumento LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')
        OR (tipoDocumento = 'RUC' AND numeroDocumento LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')
        OR  tipoDocumento IN ('CE','PAS')),
    --telefono: 9 dígitos
    CONSTRAINT CK_PERSONA_TELEFONO CHECK (telefono LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'),
    CONSTRAINT CK_PERSONA_TELAUX CHECK (
        telefonoAuxiliar IS NULL
        OR (telefonoAuxiliar LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]' AND telefonoAuxiliar <> telefono)),
    CONSTRAINT CK_PERSONA_CORREO CHECK (correo IS NULL OR correo LIKE '%_@_%._%')
);
GO

--Tabla Sucursal
CREATE TABLE Sucursal (
    idSucursal INT IDENTITY(1,1) NOT NULL,
    codigoSucursal CHAR(6) NOT NULL,
    nombre NVARCHAR(80) NOT NULL,
    departamento NVARCHAR(40) NOT NULL,
    provincia NVARCHAR(40) NOT NULL,
    distrito NVARCHAR(60) NOT NULL,
    direccion NVARCHAR(150) NOT NULL,
    activa BIT NOT NULL
        CONSTRAINT DF_SUCURSAL_activa DEFAULT 1,

    CONSTRAINT PK_SUCURSAL PRIMARY KEY (idSucursal),
    CONSTRAINT UQ_SUCURSAL_CODIGO UNIQUE (codigoSucursal),
    --tres letras y tres dígitos (AQP001)
    CONSTRAINT CK_SUCURSAL_CODIGO CHECK (
        codigoSucursal LIKE '[A-Z][A-Z][A-Z][0-9][0-9][0-9]')
);
GO

--Tabla Usuario 
CREATE TABLE Usuario (
    idUsuario INT IDENTITY(1,1) NOT NULL,
    idPersona INT NOT NULL, --FK - PERSONA (relación 1:1)
    idSucursal INT NULL, --FK - SUCURSAL (solo personal de agencia)
    nombreUsuario VARCHAR(30) NOT NULL,
    hashContrasena VARCHAR(255) NOT NULL, --contraseña cifrada
    rol VARCHAR(13) NOT NULL
        CONSTRAINT DF_USUARIO_rol DEFAULT 'CLIENTE',
    activo BIT NOT NULL
        CONSTRAINT DF_USUARIO_activo DEFAULT 1,
    fechaHoraCreacion DATETIME2(0) NOT NULL
        CONSTRAINT DF_USUARIO_fechaHoraCreacion DEFAULT SYSDATETIME(),

    CONSTRAINT PK_USUARIO PRIMARY KEY (idUsuario),
    CONSTRAINT UQ_USUARIO_PERSONA UNIQUE (idPersona), --1:1: una persona, una cuenta
    CONSTRAINT UQ_USUARIO_NOMBRE UNIQUE (nombreUsuario),
    CONSTRAINT FK_USUARIO_PERSONA FOREIGN KEY (idPersona)  REFERENCES PERSONA(idPersona),
    CONSTRAINT FK_USUARIO_SUCURSAL FOREIGN KEY (idSucursal) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT CK_USUARIO_NOMBRE CHECK (LEN(nombreUsuario) >= 4),
    CONSTRAINT CK_USUARIO_ROL CHECK (rol IN ('GERENTE','ADMINISTRADOR','TRANSPORTISTA','CLIENTE','EMPRESARIO')),
    --ADMINISTRADOR y TRANSPORTISTA exigen sucursal; los demas roles no la tienen
    CONSTRAINT CK_USUARIO_SUCURSAL CHECK (
           (rol IN ('ADMINISTRADOR','TRANSPORTISTA') AND idSucursal IS NOT NULL)
        OR (rol IN ('GERENTE','CLIENTE','EMPRESARIO') AND idSucursal IS NULL))
);
GO

--Tabla Telefono_Sucursal
CREATE TABLE Telefono_Sucursal (
    idTelefono INT IDENTITY(1,1) NOT NULL,
    idSucursal INT NOT NULL, --FK -> SUCURSAL
    numero CHAR(9) NOT NULL,

    CONSTRAINT PK_TELEFONO_SUCURSAL PRIMARY KEY (idTelefono),
    CONSTRAINT UQ_TELEFONO_SUCURSAL UNIQUE (idSucursal, numero),
    CONSTRAINT FK_TELEFONO_SUCURSAL FOREIGN KEY (idSucursal) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT CK_TELEFONO_NUMERO CHECK (numero LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')
);
GO

--Tabla Ruta
CREATE TABLE Ruta (
    idRuta INT IDENTITY(1,1) NOT NULL,
    idSucursalOrigen INT NOT NULL, --FK -> SUCURSAL
    idSucursalDestino INT NOT NULL, --FK -> SUCURSAL
    tipoEnvio VARCHAR(7) NOT NULL,
    precioBase DECIMAL(8,2) NOT NULL,
    precioPorKm DECIMAL(6,3) NOT NULL,
    distanciaKm DECIMAL(7,1) NOT NULL,
    tiempoHoras SMALLINT NOT NULL,
    vigenteDesde DATE NOT NULL
        CONSTRAINT DF_RUTA_vigenteDesde DEFAULT CAST(GETDATE() AS DATE),
    vigenteHasta DATE NULL, --NULL = precio vigente

    CONSTRAINT PK_RUTA PRIMARY KEY (idRuta),
    CONSTRAINT FK_RUTA_ORIGEN FOREIGN KEY (idSucursalOrigen) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT FK_RUTA_DESTINO FOREIGN KEY (idSucursalDestino) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT CK_RUTA_SUCURSALES CHECK (idSucursalOrigen <> idSucursalDestino),
    CONSTRAINT CK_RUTA_TIPO CHECK (tipoEnvio IN ('SOBRE','PAQUETE','CARGA')),
    CONSTRAINT CK_RUTA_PRECIOBASE CHECK (precioBase > 0),
    CONSTRAINT CK_RUTA_PRECIOKM CHECK (precioPorKm >= 0),
    CONSTRAINT CK_RUTA_DISTANCIA CHECK (distanciaKm > 0),
    CONSTRAINT CK_RUTA_TIEMPO CHECK (tiempoHoras > 0),
    CONSTRAINT CK_RUTA_VIGENCIA CHECK (vigenteHasta IS NULL OR vigenteHasta >= vigenteDesde)
);
GO

--Tabla Vehiculo
CREATE TABLE Vehiculo (
    idVehiculo INT IDENTITY(1,1) NOT NULL,
    placa VARCHAR(7) NOT NULL,
    tipoVehiculo VARCHAR(9) NOT NULL,
    capacidadKg DECIMAL(9,2) NOT NULL,
    activo BIT NOT NULL
        CONSTRAINT DF_VEHICULO_activo DEFAULT 1,

    CONSTRAINT PK_VEHICULO PRIMARY KEY (idVehiculo),
    CONSTRAINT UQ_VEHICULO_PLACA UNIQUE (placa),
    --formato ABC-123 (letras o dígitos en las 3 primeras posiciones)
    CONSTRAINT CK_VEHICULO_PLACA CHECK (
        placa LIKE '[A-Z0-9][A-Z0-9][A-Z0-9]-[0-9][0-9][0-9]'),
    CONSTRAINT CK_VEHICULO_TIPO CHECK (tipoVehiculo IN ('CAMION','FURGON','CAMIONETA','MOTO')),
    CONSTRAINT CK_VEHICULO_CAPACIDAD CHECK (capacidadKg > 0)
);
GO

--Tabla Reserva
CREATE TABLE Reserva (
    idReserva INT IDENTITY(1,1) NOT NULL,
    idPersonaRemitente INT NOT NULL, --FK -> PERSONA (quien envia)
    idPersonaDestinatario INT NOT NULL, --FK -> PERSONA (quien recibe)
    idSucursalOrigen INT NOT NULL, --FK -> SUCURSAL
    idSucursalDestino INT NOT NULL, --FK -> SUCURSAL
    codigoReserva CHAR(10) NOT NULL,
    fechaHoraReserva DATETIME2(0) NOT NULL
        CONSTRAINT DF_RESERVA_fechaHoraReserva DEFAULT SYSDATETIME(),
    canal VARCHAR(10) NOT NULL,
    descripcion NVARCHAR(200) NOT NULL,
    estadoReserva VARCHAR(11) NOT NULL
        CONSTRAINT DF_RESERVA_estadoReserva DEFAULT 'PENDIENTE',

    CONSTRAINT PK_RESERVA PRIMARY KEY (idReserva),
    CONSTRAINT UQ_RESERVA_CODIGO UNIQUE (codigoReserva),
    CONSTRAINT FK_RESERVA_REMITENTE FOREIGN KEY (idPersonaRemitente) REFERENCES PERSONA(idPersona),
    CONSTRAINT FK_RESERVA_DESTINATARIO FOREIGN KEY (idPersonaDestinatario) REFERENCES PERSONA(idPersona),
    CONSTRAINT FK_RESERVA_ORIGEN FOREIGN KEY (idSucursalOrigen) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT FK_RESERVA_DESTINO FOREIGN KEY (idSucursalDestino) REFERENCES SUCURSAL(idSucursal),
    --El código empieza con R y luego 9 digitos (R000000120)
    CONSTRAINT CK_RESERVA_CODIGO CHECK (codigoReserva LIKE 'R[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'),
    CONSTRAINT CK_RESERVA_PERSONAS CHECK (idPersonaDestinatario <> idPersonaRemitente),
    CONSTRAINT CK_RESERVA_SUCURSALES CHECK (idSucursalOrigen <> idSucursalDestino),
    CONSTRAINT CK_RESERVA_CANAL CHECK (canal IN ('WEB','TELEFONO','VENTANILLA')),
    CONSTRAINT CK_RESERVA_ESTADO CHECK (estadoReserva IN ('PENDIENTE','FORMALIZADA','CANCELADA','VENCIDA'))
);
GO

--Tabla Envio
CREATE TABLE Envio (
    idEnvio INT IDENTITY(1,1) NOT NULL,
    idReserva INT NOT NULL, --FK -> RESERVA (relacion 1:1)
    codigoSeguimiento CHAR(12) NOT NULL,
    fechaHoraRegistro DATETIME2(0) NOT NULL
        CONSTRAINT DF_ENVIO_fechaHoraRegistro DEFAULT SYSDATETIME(),
    tipoEntrega VARCHAR(9) NOT NULL
        CONSTRAINT DF_ENVIO_tipoEntrega DEFAULT 'AGENCIA',
    direccionEntrega NVARCHAR(150) NULL,
    estadoEnvio VARCHAR(11) NOT NULL
        CONSTRAINT DF_ENVIO_estadoEnvio DEFAULT 'REGISTRADO',
    fechaHoraEntrega DATETIME2(0) NULL,

    CONSTRAINT PK_ENVIO PRIMARY KEY (idEnvio),
    CONSTRAINT UQ_ENVIO_RESERVA UNIQUE (idReserva), --1:1: una reserva, un envio
    CONSTRAINT UQ_ENVIO_CODIGO UNIQUE (codigoSeguimiento),
    CONSTRAINT FK_ENVIO_RESERVA FOREIGN KEY (idReserva) REFERENCES RESERVA(idReserva),
    CONSTRAINT CK_ENVIO_CODIGO   --El codigo empieza con EN y luego 10 letras o dígitos
        CHECK (codigoSeguimiento LIKE 'EN[A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9]'),
    CONSTRAINT CK_ENVIO_TIPOENTREGA CHECK (tipoEntrega IN ('AGENCIA','DOMICILIO')),
    CONSTRAINT CK_ENVIO_DOMICILIO    --Si la entrega es a DOMICILIO, la dirección es obligatoria
        CHECK (tipoEntrega = 'AGENCIA' OR direccionEntrega IS NOT NULL),
    CONSTRAINT CK_ENVIO_ESTADO 
        CHECK (estadoEnvio IN ('REGISTRADO','EN_TRANSITO','EN_DESTINO','ENTREGADO','DEVUELTO','ANULADO')),
    CONSTRAINT CK_ENVIO_ENTREGA CHECK (  --FechaHoraEntrega se llena solo cuando el estado es ENTREGADO
           (estadoEnvio = 'ENTREGADO' AND fechaHoraEntrega IS NOT NULL)
           OR (estadoEnvio <> 'ENTREGADO' AND fechaHoraEntrega IS NULL))
);
GO

--Tabla Bulto
CREATE TABLE Bulto (
    idBulto INT IDENTITY(1,1) NOT NULL,
    idEnvio INT NOT NULL, --FK -> ENVIO
    idRuta INT NOT NULL, --FK -> RUTA (con que tarifa se cobra)
    numeroBulto SMALLINT NOT NULL,
    descripcion NVARCHAR(150) NOT NULL,
    pesoKg DECIMAL(8,2) NOT NULL,
    costo DECIMAL(10,2) NOT NULL,

    CONSTRAINT PK_BULTO PRIMARY KEY (idBulto),
    CONSTRAINT FK_BULTO_ENVIO FOREIGN KEY (idEnvio) REFERENCES ENVIO(idEnvio),
    CONSTRAINT FK_BULTO_RUTA FOREIGN KEY (idRuta) REFERENCES RUTA(idRuta),
    CONSTRAINT CK_BULTO_NUMERO CHECK (numeroBulto >= 1),
    CONSTRAINT CK_BULTO_PESO CHECK (pesoKg > 0),
    CONSTRAINT CK_BULTO_COSTO CHECK (costo >= 0)
);
GO

--Tabla Viaje
CREATE TABLE Viaje (
    idViaje INT IDENTITY(1,1) NOT NULL,
    idVehiculo INT NOT NULL, --FK -> VEHICULO
    idUsuarioTransportista INT NOT NULL, --FK -> USUARIO (rol TRANSPORTISTA)
    idSucursalOrigen INT NOT NULL, --FK -> SUCURSAL
    idSucursalDestino INT NOT NULL, --FK -> SUCURSAL
    codigoViaje CHAR(12) NOT NULL,
    fechaHoraSalida DATETIME2(0) NULL, --NULL = aún no sale
    fechaHoraLlegada DATETIME2(0) NULL,  --NULL = en ruta

    CONSTRAINT PK_VIAJE PRIMARY KEY (idViaje),
    CONSTRAINT UQ_VIAJE_CODIGO UNIQUE (codigoViaje),
    CONSTRAINT FK_VIAJE_VEHICULO FOREIGN KEY (idVehiculo) REFERENCES VEHICULO(idVehiculo),
    CONSTRAINT FK_VIAJE_TRANSPORTISTA FOREIGN KEY (idUsuarioTransportista) REFERENCES USUARIO(idUsuario),
    CONSTRAINT FK_VIAJE_ORIGEN FOREIGN KEY (idSucursalOrigen) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT FK_VIAJE_DESTINO FOREIGN KEY (idSucursalDestino) REFERENCES SUCURSAL(idSucursal),
    --El código empieza con VJ y luego 10 dígitos
    CONSTRAINT CK_VIAJE_CODIGO CHECK (codigoViaje LIKE 'VJ[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'),
    CONSTRAINT CK_VIAJE_SUCURSALES CHECK (idSucursalOrigen <> idSucursalDestino),
    --La llegada debe ser después de la salida
    CONSTRAINT CK_VIAJE_FECHAS CHECK (
        fechaHoraLlegada IS NULL
        OR (fechaHoraSalida IS NOT NULL AND fechaHoraLlegada > fechaHoraSalida))
);
GO

--Envio_Viaje
CREATE TABLE Envio_Viaje (
    idEnvioViaje INT IDENTITY(1,1) NOT NULL,
    idEnvio INT NOT NULL, --FK -> ENVIO
    idViaje INT NOT NULL, --FK -> VIAJE
    fechaHoraCarga DATETIME2(0) NOT NULL
        CONSTRAINT DF_ENVIO_VIAJE_fechaHoraCarga DEFAULT SYSDATETIME(),

    CONSTRAINT PK_ENVIO_VIAJE PRIMARY KEY (idEnvioViaje),
    --Un envio no se registra dos veces en el mismo viaje
    CONSTRAINT UQ_ENVIO_VIAJE UNIQUE (idEnvio, idViaje),
    CONSTRAINT FK_ENVIO_VIAJE_ENVIO FOREIGN KEY (idEnvio) REFERENCES ENVIO(idEnvio),
    CONSTRAINT FK_ENVIO_VIAJE_VIAJE FOREIGN KEY (idViaje) REFERENCES VIAJE(idViaje)
);
GO

--Tabla Pago
CREATE TABLE Pago (
    idPago INT IDENTITY(1,1) NOT NULL,
    idEnvio INT NOT NULL, --FK -> ENVIO
    idSucursal INT NOT NULL, --FK -> SUCURSAL (donde se cobra)
    fechaHoraPago DATETIME2(0) NOT NULL
        CONSTRAINT DF_PAGO_fechaHoraPago DEFAULT SYSDATETIME(),
    monto DECIMAL(10,2) NOT NULL,
    metodoPago VARCHAR(13) NOT NULL,
    pagadoPor VARCHAR(12) NOT NULL
        CONSTRAINT DF_PAGO_pagadoPor DEFAULT 'REMITENTE',
    estadoPago VARCHAR(10) NOT NULL
        CONSTRAINT DF_PAGO_estadoPago DEFAULT 'REGISTRADO',

    CONSTRAINT PK_PAGO PRIMARY KEY (idPago),
    CONSTRAINT FK_PAGO_ENVIO FOREIGN KEY (idEnvio) REFERENCES ENVIO(idEnvio),
    CONSTRAINT FK_PAGO_SUCURSAL FOREIGN KEY (idSucursal) REFERENCES SUCURSAL(idSucursal),
    CONSTRAINT CK_PAGO_MONTO CHECK (monto > 0),
    CONSTRAINT CK_PAGO_METODO CHECK (metodoPago IN ('EFECTIVO','TARJETA','BILLETERA','TRANSFERENCIA')),
    CONSTRAINT CK_PAGO_PAGADOPOR CHECK (pagadoPor IN ('REMITENTE','DESTINATARIO')),
    CONSTRAINT CK_PAGO_ESTADO CHECK (estadoPago IN ('REGISTRADO','ANULADO'))
);
GO

--3. Insertar Datos de Prueba uwu

--5 personas (la 4 es una empresa con RUC)
INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono, telefonoAuxiliar, correo) VALUES
    ('DNI','45781236', N'Rosa María Quispe Mamani', '987654321', '951222333', 'rosa.quispe@mail.com'),
    ('DNI','70234561', N'Luis Alberto Flores Condori', '987654322', NULL, 'luis.flores@mail.com'),
    ('DNI','71889345', N'María Elena Rojas Ccori', '987654323', NULL, NULL),
    ('RUC','20123456789', N'Comercial Sur S.A.C.', '987654324', NULL, 'ventas@comercialsur.pe'),
    ('DNI','72004518', N'Pedro Mamani Huanca', '987654325', NULL, NULL);

--5 sucursales (TAC001 aparece dada de baja: activa = 0)
INSERT INTO Sucursal (codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa) VALUES
    ('LIM001', N'Agencia Lima Centro', N'Lima', N'Lima', N'Cercado de Lima', N'Av. Grau 450', 1),
    ('AQP001', N'Agencia Arequipa Centro', N'Arequipa', N'Arequipa', N'Cercado', N'Av. Ejército 512', 1),
    ('MOQ001', N'Agencia Moquegua', N'Moquegua', N'Mariscal Nieto', N'Moquegua', N'Jr. Ancash 325', 1),
    ('ILO001', N'Agencia Ilo', N'Moquegua', N'Ilo', N'Ilo', N'Av. Costanera 120', 1),
    ('TAC001', N'Agencia Tacna', N'Tacna', N'Tacna', N'Tacna', N'Av. Bolognesi 640', 0);

--5 usuarios: uno por cada rol (ADMINISTRADOR y TRANSPORTISTA llevan sucursal)
INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol) VALUES
    (1, NULL, 'rquispe', '$2y$12$Qm1aXk0v9ZtLr3Hn7sBd2uYc8WqEoPfJgTzNvMxKiRlUhDwSyAbCe', 'GERENTE'),
    (2, 3, 'lflores', '$2y$12$Tn4bYl1w0AuMs4Io8tCe3vZd9XrFpQgKhUaOwNyLjSmViExTzBcDf', 'ADMINISTRADOR'),
    (3, NULL, 'mrojas', '$2y$12$Up5cZm2x1BvNt5Jp9uDf4wAe0YsGqRhLiVbPxOzMkTnWjFyUaCdEg', 'CLIENTE'),
    (4, NULL, 'comsur', '$2y$12$Vq6dAn3y2CwOu6Kq0vEg5xBf1ZtHrSiMjWcQyPaNlUoXkGzVbDeFh', 'EMPRESARIO'),
    (5, 2, 'pmamani', '$2y$12$Wr7eBo4z3DxPv7Lr1wFh6yCg2AuIsTjNkXdRzQbOmVpYlHaWcEfGi', 'TRANSPORTISTA');

--5 telefonos de sucursal (AQP001 tiene dos: atributo multivaluado)
INSERT INTO TELEFONO_SUCURSAL (idSucursal, numero) VALUES
    (1, '014251230'),
    (2, '054201450'),
    (2, '054201451'),
    (3, '053461120'),
    (4, '053481560');

--5 rutas. costo del bulto = precioBase + precioPorKm * distanciaKm
INSERT INTO RUTA (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, 
    distanciaKm, tiempoHoras, vigenteDesde) VALUES
    (3, 2, 'SOBRE', 8.00, 0.005, 230.0, 4, '2026-09-01'),
    (3, 2, 'PAQUETE', 10.00, 0.020, 230.0, 4, '2026-09-01'),
    (2, 1, 'PAQUETE', 10.00, 0.020, 1010.0, 18, '2026-09-01'),
    (1, 4, 'CARGA', 40.00, 0.080, 1200.0, 24, '2026-09-01'),
    (4, 3, 'SOBRE', 8.00, 0.005, 100.0, 2, '2026-09-01');

--5 vehiculos
INSERT INTO VEHICULO (placa, tipoVehiculo, capacidadKg) VALUES
    ('V2A-845', 'CAMION', 18000.00),
    ('B7K-210', 'FURGON', 6500.00),
    ('C3M-956', 'CAMIONETA', 1500.00),
    ('D8F-102', 'CAMION', 20000.00),
    ('A1X-374', 'MOTO', 150.00);

--5 reservas (remitente y destinatario siempre distintos; origen y destino distintos)
INSERT INTO RESERVA (codigoReserva, idPersonaRemitente, idPersonaDestinatario, idSucursalOrigen, 
    idSucursalDestino, fechaHoraReserva, canal, descripcion, estadoReserva) VALUES
    ('R000000001', 3, 2, 3, 2, '2026-10-10 09:15:00', 'WEB', N'Documentos y ropa', 'FORMALIZADA'),
    ('R000000002', 4, 1, 2, 1, '2026-10-11 08:40:00', 'VENTANILLA', N'Repuestos de motor', 'FORMALIZADA'),
    ('R000000003', 1, 5, 1, 4, '2026-10-12 10:20:00', 'TELEFONO', N'Caja con herramientas', 'FORMALIZADA'),
    ('R000000004', 5, 3, 4, 3, '2026-10-13 06:50:00', 'WEB', N'Sobre con documentos', 'FORMALIZADA'),
    ('R000000005', 2, 4, 3, 2, '2026-10-14 13:30:00', 'VENTANILLA', N'Mercadería para tienda', 'FORMALIZADA');

--5 envios (uno por reserva: relacion 1:1)
INSERT INTO ENVIO (idReserva, codigoSeguimiento, fechaHoraRegistro, tipoEntrega, direccionEntrega, estadoEnvio, 
    fechaHoraEntrega) VALUES
    (1, 'EN7K4Q2M9X1B', '2026-10-10 10:02:00', 'AGENCIA', NULL, 'ENTREGADO', '2026-10-12 11:30:00'),
    (2, 'ENB3F8L0Q2ZD', '2026-10-11 09:00:00', 'DOMICILIO', N'Jr. Lampa 345, Cercado de Lima', 'EN_TRANSITO', NULL),
    (3, 'ENXY91K7C4MA', '2026-10-12 11:00:00', 'AGENCIA', NULL, 'REGISTRADO', NULL),
    (4, 'ENM2P6T8R1WQ', '2026-10-13 07:20:00', 'AGENCIA', NULL, 'EN_DESTINO', NULL),
    (5, 'ENZ5H0D3V7NJ', '2026-10-14 14:00:00', 'AGENCIA', NULL, 'EN_TRANSITO', NULL);

--6 bultos (el envio 1 lleva un sobre y una caja, cada uno con su tarifa)
INSERT INTO BULTO (idEnvio, idRuta, numeroBulto, descripcion, pesoKg, costo) VALUES
    (1, 1, 1, N'Sobre con documentos', 0.40, 9.15),
    (1, 2, 2, N'Caja con ropa', 8.80, 14.60),
    (2, 3, 1, N'Caja de repuestos de motor', 12.00, 30.20),
    (3, 4, 1, N'Caja con herramientas', 85.00, 136.00),
    (4, 5, 1, N'Sobre con documentos', 0.60, 8.50),
    (5, 2, 1, N'Mercadería para tienda', 18.50, 14.60);

--5 viajes (el 2 sigue en ruta; el 3 todavía no sale)
INSERT INTO VIAJE (codigoViaje, idVehiculo, idUsuarioTransportista, idSucursalOrigen, 
    idSucursalDestino, fechaHoraSalida, fechaHoraLlegada) VALUES
    ('VJ2026101001', 3, 5, 3, 2, '2026-10-10 14:00:00', '2026-10-10 18:30:00'),
    ('VJ2026101101', 1, 5, 2, 1, '2026-10-11 20:25:00', NULL),
    ('VJ2026101201', 4, 5, 1, 4, NULL, NULL),
    ('VJ2026101301', 2, 5, 4, 3, '2026-10-13 07:00:00', '2026-10-13 09:15:00'),
    ('VJ2026101402', 2, 5, 3, 2, '2026-10-14 15:00:00', NULL);

--5 registros envio-viaje (tabla intermedia)
INSERT INTO ENVIO_VIAJE (idEnvio, idViaje, fechaHoraCarga) VALUES
    (1, 1, '2026-10-10 13:40:00'),
    (2, 2, '2026-10-11 19:40:00'),
    (3, 3, '2026-10-12 18:00:00'),
    (4, 4, '2026-10-13 06:30:00'),
    (5, 5, '2026-10-14 14:20:00');

--5 pagos (el pago 4 se hace en destino: pagadoPor = DESTINATARIO)
INSERT INTO PAGO (idEnvio, idSucursal, fechaHoraPago, monto, metodoPago, pagadoPor, estadoPago) VALUES
    (1, 3, '2026-10-10 10:04:00', 23.75, 'EFECTIVO', 'REMITENTE', 'REGISTRADO'),
    (2, 2, '2026-10-11 09:05:00', 30.20, 'BILLETERA', 'REMITENTE', 'REGISTRADO'),
    (3, 1, '2026-10-12 11:05:00', 136.00, 'TRANSFERENCIA', 'REMITENTE', 'REGISTRADO'),
    (4, 3, '2026-10-13 12:10:00', 8.50, 'EFECTIVO', 'DESTINATARIO', 'REGISTRADO'),
    (5, 3, '2026-10-14 14:05:00', 14.60, 'TARJETA', 'REMITENTE', 'REGISTRADO');
GO

--4. Ver las tablas

--Ver el contenido de cada tabla
SELECT * FROM PERSONA; 
SELECT * FROM SUCURSAL;
SELECT * FROM USUARIO;
SELECT * FROM TELEFONO_SUCURSAL;
SELECT * FROM RUTA;
SELECT * FROM VEHICULO;
SELECT * FROM RESERVA;
SELECT * FROM ENVIO;
SELECT * FROM BULTO;
SELECT * FROM VIAJE;
SELECT * FROM ENVIO_VIAJE;
SELECT * FROM PAGO;
GO


--5. Pruebas de resctricciones

--DNI con 7 dígitos
INSERT INTO PERSONA (tipoDocumento, numeroDocumento, nombreCompleto, telefono) 
    VALUES ('DNI','1234567', N'Prueba DNI corto', '987000001');
--Teléfono con letras
    INSERT INTO PERSONA (tipoDocumento, numeroDocumento, nombreCompleto, telefono)
    VALUES ('DNI','70000001', N'Prueba telefono', '98765432A');
--Documento repetido (UNIQUE tipo + numero)
    INSERT INTO PERSONA (tipoDocumento, numeroDocumento, nombreCompleto, telefono)
    VALUES ('DNI','45781236', N'Persona repetida', '987000002');
--Código de sucursal en minusculas
    INSERT INTO SUCURSAL (codigoSucursal, nombre, departamento, provincia, distrito, direccion)
    VALUES ('abc123', N'Agencia de prueba', N'Puno', N'Puno', N'Puno', N'Jr. Prueba 1');
--Canal de reserva no permitido
    INSERT INTO RESERVA (codigoReserva, idPersonaRemitente, idPersonaDestinatario, 
    idSucursalOrigen, idSucursalDestino, canal, descripcion)
    VALUES ('R000000099', 1, 2, 1, 2, 'FAX', N'Prueba canal');
--Remitente igual al destinatario
    INSERT INTO RESERVA (codigoReserva, idPersonaRemitente, idPersonaDestinatario, 
    idSucursalOrigen, idSucursalDestino, canal, descripcion)
    VALUES ('R000000098', 1, 1, 1, 2, 'WEB', N'Prueba personas');

--Dos envios para la misma reserva (UNIQUE de la relacion 1:1)
    INSERT INTO ENVIO (idReserva, codigoSeguimiento) VALUES (1, 'ENAAAAAAAAAA');
--Marcar ENTREGADO sin fecha de entrega
    UPDATE ENVIO SET estadoEnvio = 'ENTREGADO', fechaHoraEntrega = NULL WHERE idEnvio = 3;
--Bulto con peso negativo
    INSERT INTO BULTO (idEnvio, idRuta, numeroBulto, descripcion, pesoKg, costo)
    VALUES (1, 1, 3, N'Prueba peso', -5, 10);
--Bulto de un envio que no existe (FOREIGN KEY)
    INSERT INTO BULTO (idEnvio, idRuta, numeroBulto, descripcion, pesoKg, costo)
    VALUES (999, 1, 1, N'Prueba FK', 1, 10);
--ADMINISTRADOR sin sucursal
    UPDATE USUARIO SET idSucursal = NULL WHERE idUsuario = 2;
--Viaje que llega antes de salir
    UPDATE VIAJE SET fechaHoraLlegada = '2026-10-09 10:00:00' WHERE idViaje = 1;
--Pago con monto cero
    INSERT INTO PAGO (idEnvio, idSucursal, monto, metodoPago) VALUES (1, 3, 0, 'EFECTIVO');
GO

