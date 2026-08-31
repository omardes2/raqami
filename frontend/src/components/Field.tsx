import type { InputHTMLAttributes } from 'react'

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
}

export default function Field({ label, error, id, ...props }: FieldProps) {
  const inputId = id || props.name
  return (
    <div className="field">
      <label htmlFor={inputId}>{label}</label>
      <input id={inputId} {...props} />
      {error && <p className="field-error">{error}</p>}
    </div>
  )
}
