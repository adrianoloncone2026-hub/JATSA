// Campo de formulario accesible: etiqueta, control y mensaje de error enlazados.
export default function CampoTexto({ id, etiqueta, error = '', como = 'input', children, ...propiedades }) {
  const idError = error ? `${id}-error` : undefined
  const comunes = { id, 'aria-invalid': error ? 'true' : undefined, 'aria-describedby': idError, ...propiedades }

  return (
    <div className="campo">
      <label htmlFor={id}>{etiqueta}</label>
      {como === 'select' ? <select {...comunes}>{children}</select> : <input {...comunes} />}
      {error && (
        <p id={idError} className="campo-error">
          {error}
        </p>
      )}
    </div>
  )
}
