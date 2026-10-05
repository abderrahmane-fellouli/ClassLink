import { useEffect, useState } from 'react'
import { useI18n } from '../i18n'
import { Alert, Btn } from './UI'

export function CopyButton({ value }: { value: string }) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)
  const [pending, setPending] = useState(false)
  const [failed, setFailed] = useState(false)
  useEffect(() => { setCopied(false); setFailed(false) }, [value])
  async function copy() {
    if (pending || !value) return
    setPending(true)
    setFailed(false)
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
    } catch { setCopied(false); setFailed(true) }
    finally { setPending(false) }
  }
  return <div>
    <Btn size="sm" variant="secondary" disabled={!value || pending} onClick={() => void copy()}>{t(copied ? 'common.copied' : 'common.copy')}</Btn>
    {copied && <span role="status" className="sr-only">{t('common.copied')}</span>}
    {failed && <Alert type="error" message={t('copy.failed')}/>}
  </div>
}
