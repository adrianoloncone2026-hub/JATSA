import { useCallback, useEffect, useState } from 'react'
import { actualizarSucursal, cambiarEstadoSucursal, crearSucursal, listarSucursales } from '../../api/catalogos.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { formatoTelefono } from '../../utils/formato.js'

const VACIO = { codigoSucursal: '', nombre: '', departamento: '', provincia: '', distrito: '', direccion: '', telefonos: '' }

// Pestaña «Sucursales» de Catálogos (diapositiva 29). Las sucursales no se borran: se desactivan (si no tienen personal activo,
export default function CatalogoSucursales() {
  const [estado, setEstado] = useState({ fase: 'cargando', sucursales: [], mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [formulario, setFormulario] = useState(null)
  const [errores, setErrores] = useState({})
  const [enviando, setEnviando] = useState(false)
  const [aviso, setAviso] = useState(null)
  const [confirmando, setConfirmando] = useState('')

  const recargar = useCallback(() => setIntento((n) => n + 1), [])
  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarSucursales(control.signal)
      .then((datos) => setEstado({ fase: 'listo', sucursales: datos.sucursales, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setEstado({ fase: 'error', sucursales: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  const abrirNueva = () => {
    setAviso(null)
    setErrores({})
    setFormulario({ editando: null, campos: VACIO })
  }
  const abrirEdicion = (s) => {
    setAviso(null)
    setErrores({})
    setFormulario({ editando: s.codigo, campos: { codigoSucursal: s.codigo, nombre: s.nombre, departamento: s.departamento, provincia: s.provincia, distrito: s.distrito, direccion: s.direccion, telefonos: s.telefonos.join(', ') } })
  }
  const cambiar = (campo, valor) => setFormulario((f) => ({ ...f, campos: { ...f.campos, [campo]: valor } }))

  async function guardar(evento) {
    evento.preventDefault()
    const { editando, campos } = formulario
    const telefonos = campos.telefonos.split(/[,;\n]/).map((t) => t.trim()).filter(Boolean)
    const cuerpo = { ...campos, telefonos }
    setEnviando(true)
    setErrores({})
    try {
      const guardada = editando ? await actualizarSucursal(editando, cuerpo) : await crearSucursal(cuerpo)
      setAviso({ tipo: 'ok', texto: editando ? `Sucursal ${guardada.codigo} actualizada.` : `Sucursal ${guardada.codigo} creada.` })
      setFormulario(null)
      recargar()
    } catch (fallo) {
      if (Object.keys(fallo.campos ?? {}).length > 0) setErrores(fallo.campos)
      else setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setEnviando(false)
    }
  }

  async function alternar(s) {
    setConfirmando('')
    setAviso(null)
    try {
      const resultado = await cambiarEstadoSucursal(s.codigo, !s.activa)
      setAviso({ tipo: 'ok', texto: `Sucursal ${resultado.codigo} ${resultado.activa ? 'activada' : 'desactivada'}.` })
      recargar()
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
    }
  }

  return (
    <section aria-label="Sucursales">
      <div className="barra-historial">
        <h2>Sucursales</h2>
        <button type="button" className="boton boton-azul" onClick={abrirNueva}>
          Nueva sucursal
        </button>
      </div>
      {aviso && (
        <div className={aviso.tipo === 'ok' ? 'resultado ok' : 'resultado error'} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {formulario && (
        <form className="panel formulario-catalogo" onSubmit={guardar} noValidate aria-label={formulario.editando ? 'Editar sucursal' : 'Nueva sucursal'}>
          <h3>{formulario.editando ? `Editar ${formulario.editando}` : 'Nueva sucursal'}</h3>
          <div className="rejilla-formulario">
            <CampoTexto id="suc-codigo" etiqueta="Código (3 letras y 3 dígitos)" value={formulario.campos.codigoSucursal} disabled={Boolean(formulario.editando)} maxLength={6} placeholder="AQP001" error={errores.codigoSucursal} onChange={(e) => cambiar('codigoSucursal', e.target.value)} />
            <CampoTexto id="suc-nombre" etiqueta="Nombre" value={formulario.campos.nombre} maxLength={80} error={errores.nombre} onChange={(e) => cambiar('nombre', e.target.value)} />
            <CampoTexto id="suc-departamento" etiqueta="Departamento" value={formulario.campos.departamento} maxLength={40} error={errores.departamento} onChange={(e) => cambiar('departamento', e.target.value)} />
            <CampoTexto id="suc-provincia" etiqueta="Provincia" value={formulario.campos.provincia} maxLength={40} error={errores.provincia} onChange={(e) => cambiar('provincia', e.target.value)} />
            <CampoTexto id="suc-distrito" etiqueta="Distrito" value={formulario.campos.distrito} maxLength={60} error={errores.distrito} onChange={(e) => cambiar('distrito', e.target.value)} />
            <CampoTexto id="suc-direccion" etiqueta="Dirección" value={formulario.campos.direccion} maxLength={150} error={errores.direccion} onChange={(e) => cambiar('direccion', e.target.value)} />
            <CampoTexto id="suc-telefonos" etiqueta="Teléfonos (9 dígitos, separados por coma)" value={formulario.campos.telefonos} error={errores.telefonos} onChange={(e) => cambiar('telefonos', e.target.value)} />
          </div>
          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando}>
              {enviando ? 'Guardando…' : 'Guardar'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setFormulario(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando las sucursales…
        </p>
      )}
      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las sucursales.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={recargar}>
            Reintentar
          </button>
        </div>
      )}
      {estado.fase === 'listo' && estado.sucursales.length === 0 && (
        <div className="panel estado-vacio">
          <p>Todavía no hay sucursales. Crea la primera.</p>
        </div>
      )}
      {estado.fase === 'listo' && estado.sucursales.length > 0 && (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas tabla-catalogo">
            <caption className="solo-lectores">Sucursales</caption>
            <thead>
              <tr>
                <th scope="col">Código</th>
                <th scope="col">Nombre</th>
                <th scope="col">Ubicación</th>
                <th scope="col">Teléfonos</th>
                <th scope="col">Estado</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {estado.sucursales.map((s) => (
                <tr key={s.codigo}>
                  <th scope="row">{s.codigo}</th>
                  <td data-etiqueta="Nombre">{s.nombre}</td>
                  <td data-etiqueta="Ubicación">
                    {s.distrito}, {s.provincia}, {s.departamento} · {s.direccion}
                  </td>
                  <td data-etiqueta="Teléfonos">{s.telefonos.length > 0 ? s.telefonos.map(formatoTelefono).join(' · ') : '—'}</td>
                  <td data-etiqueta="Estado">
                    <InsigniaEstado estado={s.activa ? 'ACTIVA' : 'INACTIVA'} />
                  </td>
                  <td data-etiqueta="Acciones" className="celda-accion">
                    <button type="button" className="boton boton-claro" onClick={() => abrirEdicion(s)} aria-label={`Editar ${s.codigo}`}>
                      Editar
                    </button>
                    {confirmando === s.codigo ? (
                      <>
                        <button type="button" className="boton boton-peligro" onClick={() => alternar(s)}>
                          Confirmar
                        </button>
                        <button type="button" className="boton boton-claro" onClick={() => setConfirmando('')}>
                          No
                        </button>
                      </>
                    ) : (
                      <button type="button" className="boton boton-claro" onClick={() => (s.activa ? setConfirmando(s.codigo) : alternar(s))} aria-label={`${s.activa ? 'Desactivar' : 'Activar'} ${s.codigo}`}>
                        {s.activa ? 'Desactivar' : 'Activar'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}