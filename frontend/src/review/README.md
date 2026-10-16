# TEMPORARY — ClassLink V2 visual review

Public URL: `/v2-review` (and its trailing-slash equivalent). Delete after design approval.

## Isolation

- `App.tsx` selects this entry **before mounting the real AuthProvider**. A previous
  real browser token is neither adopted nor cleared by review.
- The entry supplies an explicitly synthetic presentation context with `token: null`.
  Its authenticated-looking UI status only lets the existing frontend components
  render; it creates no actual login, session, claim or backend authorization.
- A memory data router reuses the existing `/app` route elements, guards and AppShell.
  Public browser location remains `/v2-review` while internal preview screens change.
  Delegate uses the native Student role plus fictional scoped responsibilities.
- `lib/api.ts` fails closed on this exact public entry before `buildHeaders`, fetch,
  token handling or protected downloads. A missing adapter produces a local error.
  If browser history changes the URL while a review adapter is still mounted, it
  also refuses network access until the synthetic boundary is unmounted. The entry
  observes browser back/forward so normal pages remount their real providers.
- `ReviewStore` has no network client. Reads, edits, previews, uploads, notifications
  and downloads are synthetic local demonstrations. Unsupported read surfaces show
  a local notice rather than querying a server. Authentication endpoints are refused.
- Only theme/language visual preferences use the existing preference architecture.
  Review domain data lives in this tab's memory and resets on reload/reset/role change.
- Reserved `example.invalid` addresses, fictional people and fictional data only.
  No import/provisioning code, production roster or real messages/grades are included.

The toolbar provides roles, Light/Dark, FR/EN, screen navigation, and normal/empty/
loading/error states. Use module/profile/class tabs and normal dialogs to inspect
the real controls. Actions display a local-only notice; no server result is claimed.

## Verification

- Frontend tests include fail-closed API verbs/downloads, zero fetch, preserved auth
  storage, StrictMode role/navigation, cross-path fail-closed behavior and restored
  normal transport only after adapter cleanup.
- `node scripts/v2-review-browser.mjs` runs production-preview Chromium/Firefox
  checks with **no backend**, blocks and counts all fetch/XHR/API requests, visits
  all catalog screens in both themes at 390/768/1440, checks FR/EN and visual states,
  and exercises local grade save/import/roster preview. Evidence is ignored under
  `artifacts/v2-review/`.

## Complete removal

1. Delete `src/review/`, `src/i18n/review.ts`, the review test and browser QA script.
2. Remove the public-entry lazy branch/imports from `App.tsx`.
3. Remove only the review early-return branches/import from `lib/api.ts`.
4. Remove `AuthPresentationProvider` and the review-only type export if unused.
5. Remove the review back-context import/override from `UI.tsx`; keep the ordinary
   `back` callback. The optional button `aria-pressed` support is general semantics.
6. Remove the review dictionary imports/spreads from `i18n/fr.ts` and `en.ts`.
7. Verify ordinary frontend/auth regression tests, TypeScript and production build.

The existing temporary **backend** review-login mechanism is deliberately unchanged
by this feature. Remove it separately after the user's design review, as agreed.
