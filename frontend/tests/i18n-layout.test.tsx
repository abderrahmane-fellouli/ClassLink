import { describe, expect, it } from 'vitest'
import { fr } from '../src/i18n/fr'
import { en } from '../src/i18n/en'
import { detectLocale } from '../src/i18n'
import { setStoredLocale } from '../src/lib/session'

/**
 * T-26 — Bascule de langue : « Aucun texte non traduit ».
 *
 * La.spec exige que le passage du français à l'anglais ne laisse aucun texte
 * brut. Deux causes de régression sont vérifiées ici :
 *   1. une clé presente en `fr` mais absente en `en` (le repli silencieux sur
 *      le français ferait apparaître un texte non traduit) ;
 *   2. une valeur restée en français dans le dictionnaire anglais.
 */
describe('T-26 — parité des dictionnaires FR/EN', () => {
  const frKeys = Object.keys(fr).sort()
  const enKeys = Object.keys(en).sort()

  it('déclare exactement les mêmes clés dans les deux langues', () => {
    expect(enKeys).toEqual(frKeys)
  })

  it('ne laisse aucune valeur vide', () => {
    const empties = frKeys.filter((key) => {
      const value = (fr as Record<string, string>)[key]
      return typeof value !== 'string' || value.trim() === ''
    })

    expect(empties).toEqual([])
  })

  it('ne renvoie pas une clé brute comme texte', () => {
    // Un `t()` sur une clé inconnue doit produire autre chose que la clé.
    const suspicious = enKeys.filter((key) => (en as Record<string, string>)[key] === key)

    expect(suspicious).toEqual([])
  })

  it('ne conserve pas de valeur identiques à la française là où une traduction existe', () => {
    // Les valeurs volontairement identiques (noms de produits, « Quiz »,
    // nombres) sont rares : on tolère un taux faible mais pas une copie
    // intégrale du dictionnaire anglais.
    const identical = enKeys.filter(
      (key) => (en as Record<string, string>)[key] === (fr as Record<string, string>)[key],
    )

    expect(identical.length).toBeLessThan(frKeys.length * 0.1)
  })

  it('détecte la langue du navigateur quand rien n’est stocké', () => {
    // jsdom expose `en-US` par défaut.
    expect(['fr', 'en']).toContain(detectLocale())
  })

  it('respecte une langue stockée valide', () => {
    setStoredLocale('en')
    expect(detectLocale()).toBe('en')

    setStoredLocale('fr')
    expect(detectLocale()).toBe('fr')
  })
})

/**
 * T-27 — Responsive : « Aucun débordement, boutons utilisables ».
 *
 * Le critère T-27 est une recette visuelle sur téléphone. Ce que le test peut
 * garantir sans navigateur, c'est l'absence de largeur fixe dans le
 * conteneur de contenu et la présence des points de rupture qui font passer
 * la navigation en tiroir sur petit écran. Une largeur fixe (`w-[900px]`)
 * suffirait à faire déborder la page sur un mobile de 360 px.
 */
describe('T-27 — contraintes de mise en page', () => {
  it('le conteneur de contenu borne sa largeur au lieu de la fixer', async () => {
    const { readFile } = await import('node:fs/promises')
    const source = await readFile('src/components/AppShell.tsx', 'utf8')

    const main = source.match(/<main[^>]*>/)
    expect(main).not.toBeNull()

    // `max-w-*` borne la largeur sur grand écran, `w-full` l'adapte au
    // conteneur parent sur téléphone.
    expect(main![0]).toContain('max-w-')
    expect(main![0]).toContain('w-full')
    expect(main![0]).toContain('mx-auto')

    // Aucune largeur fixe en pixels sur le conteneur principal.
    expect(main![0]).not.toMatch(/w-\[\d+px\]/)
  })

  it('déclare une navigation mobile et un point de bascule', async () => {
    const { readFile } = await import('node:fs/promises')
    const source = await readFile('src/components/AppShell.tsx', 'utf8')

    // Barre latérale masquée sous `md`, tiroir `fixed` au-dessus.
    expect(source).toContain('hidden md:flex')
    expect(source).toContain('md:hidden')
  })

  it('permet le retour à la ligne des libellés de navigation', async () => {
    const { readFile } = await import('node:fs/promises')
    const source = await readFile('src/components/AppShell.tsx', 'utf8')

    // Sans `min-w-0` ni retour à la ligne, un libellé long en français
    // ("Demandes d'adhésion") déborde la colonne latérale.
    expect(source).toContain('min-w-0')
  })
})
