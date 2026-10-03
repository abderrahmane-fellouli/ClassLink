import { useState } from 'react'
import { useI18n } from '../i18n'
import { errorMessage } from '../lib/api'
import { useAction } from '../lib/useAsync'
import { Alert, Btn, Dialog, Input, Toggle } from './UI'

export function EditButton({ initial, labels, save, onSaved }: {
  initial: Record<string, string | boolean>
  labels: Record<string, string>
  save: (values: Record<string, string | boolean>) => Promise<unknown>
  onSaved: () => void
}) {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)
  const [values, setValues] = useState(initial)
  const action = useAction()
  return <>
    <Btn size="sm" variant="secondary" onClick={() => { setValues(initial); action.clear(); setOpen(true) }}>{t('common.edit')}</Btn>
    {open && <Dialog title={t('common.edit')} onClose={() => { if (!action.pending) setOpen(false) }}>
      <form className="space-y-4" onSubmit={event => { event.preventDefault(); void action.run(async () => { await save(values); setOpen(false); onSaved() }) }}>
        {action.error && <Alert type="error" message={errorMessage(action.error, t('error.unknown'))}/>}
        {Object.entries(values).map(([key, value]) => typeof value === 'boolean'
          ? <Toggle key={key} label={labels[key]} checked={value} onChange={next => setValues({ ...values, [key]: next })}/>
          : <Input key={key} label={labels[key]} value={value} onChange={next => setValues({ ...values, [key]: next })}/>)}
        <div className="flex justify-end gap-2"><Btn variant="ghost" disabled={action.pending} onClick={() => setOpen(false)}>{t('common.cancel')}</Btn><Btn type="submit" disabled={action.pending}>{t('common.save')}</Btn></div>
      </form>
    </Dialog>}
  </>
}
