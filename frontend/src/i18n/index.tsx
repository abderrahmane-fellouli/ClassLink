import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { fr, type TranslationKey } from './fr'
import { en } from './en'
import { getStoredLocale, setStoredLocale } from '../lib/session'
import type { Locale } from '../lib/types'

const dictionaries = { fr, en } as const

export type Translate = (key: TranslationKey, params?: Record<string, string | number>) => string

interface I18nValue {
  locale: Locale
  setLocale: (locale: Locale) => void
  t: Translate
  formatDate: (value: string | Date | null | undefined) => string
  formatDateTime: (value: string | Date | null | undefined) => string
  formatRelative: (value: string | Date | null | undefined) => string
  formatNumber: (value: number) => string
}

const I18nContext = createContext<I18nValue | null>(null)

/** Langue par défaut : préférence stockée, sinon navigateur, sinon FR. */
export function detectLocale(): Locale {
  const stored = getStoredLocale()
  if (stored) return stored

  const candidates = [typeof navigator === 'undefined' ? '' : navigator.language, ...(navigator?.languages ?? [])]
  for (const candidate of candidates) {
    const tag = candidate.toLowerCase()
    if (tag.startsWith('fr')) return 'fr'
    if (tag.startsWith('en')) return 'en'
  }

  return 'fr'
}

function interpolate(template: string, params?: Record<string, string | number>): string {
  if (!params) return template
  return template.replace(/\{\{(\w+)\}\}/g, (match, key: string) =>
    key in params ? String(params[key]) : match,
  )
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(detectLocale)
  useEffect(() => { document.documentElement.lang = locale }, [locale])

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next)
    setStoredLocale(next)
    if (typeof document !== 'undefined') {
      document.documentElement.lang = next
    }
  }, [])

  const value = useMemo<I18nValue>(() => {
    const dictionary = dictionaries[locale]

    const t: Translate = (key, params) => interpolate(dictionary[key] ?? fr[key] ?? key, params)

    const toDate = (input: string | Date | null | undefined): Date | null => {
      if (!input) return null
      const normalized = typeof input === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(input)
        ? input.replace(' ', 'T') + 'Z' : input
      const date = normalized instanceof Date ? normalized : new Date(normalized)
      return Number.isNaN(date.getTime()) ? null : date
    }

    return {
      locale,
      setLocale,
      t,
      formatDate: input => {
        const date = toDate(input)
        return date ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'Africa/Casablanca' }).format(date) : '—'
      },
      formatDateTime: input => {
        const date = toDate(input)
        return date
          ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Casablanca' }).format(date)
          : '—'
      },
      formatRelative: input => {
        const date = toDate(input)
        if (!date) return '—'

        const seconds = Math.round((date.getTime() - Date.now()) / 1000)
        const magnitude = Math.abs(seconds)

        const pick = (): [Intl.RelativeTimeFormatUnit, number] => {
          if (magnitude < 60) return ['second', seconds]
          if (magnitude < 3600) return ['minute', Math.round(seconds / 60)]
          if (magnitude < 86400) return ['hour', Math.round(seconds / 3600)]
          if (magnitude < 604800) return ['day', Math.round(seconds / 86400)]
          return ['week', Math.round(seconds / 604800)]
        }

        const [unit, value] = pick()
        return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(value, unit)
      },
      formatNumber: n => new Intl.NumberFormat(locale).format(n),
    }
  }, [locale, setLocale])

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}

export function useI18n(): I18nValue {
  const context = useContext(I18nContext)
  if (!context) {
    throw new Error('useI18n doit être utilisé dans un I18nProvider.')
  }
  return context
}
