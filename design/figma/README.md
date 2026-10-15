# ClassLink Figma redesign

`ClassLink.fig` is the native Figma source/snapshot for the ClassLink redesign.
It contains the current foundations, semantic variables, reusable components,
responsive representative screens, Light/Dark designs, Student V2 work, and V2
foundation explorations.

## Design scope and product references

The Figma file defines visual presentation only. Existing application behavior,
routes, permissions, APIs, and authorization remain authoritative.

The verified product/UX references are:

- [`docs/FEATURE_UX_VALIDATION.md`](../../docs/FEATURE_UX_VALIDATION.md)
- [`docs/UX_SATISFACTION_AUDIT.md`](../../docs/UX_SATISFACTION_AUDIT.md)

The redesign is not complete yet. Many remaining application screens still
need to be expanded in Figma.

## Current review exports

The five current references below are exported from the native `ClassLink.fig`
source. They are multi-artboard visual references, not the full application screen
inventory. The images and native source are retained without regeneration.

| Export                                                                                 | Purpose                                                                                            |
| -------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| [`student-v2-responsive-light-dark.png`](exports/student-v2-responsive-light-dark.png) | Primary V2 visual direction: Student compositions in Light/Dark at phone, tablet and desktop sizes |
| [`foundation-v2-explorations.png`](exports/foundation-v2-explorations.png)             | V2 foundation explorations for Assignment Detail, Teacher Grade Grid and Admin Roster/Import       |
| [`responsive-stress-findings.png`](exports/responsive-stress-findings.png)             | Adaptive composition and width constraints                                                         |
| [`localization-stress-findings.png`](exports/localization-stress-findings.png)         | French/English expansion, long-content and stable interaction-state constraints                    |
| [`original-baseline.png`](exports/original-baseline.png)                               | Historical comparison only; not the target for new implementation                                  |

The V2 system guides the complete current frontend, including screens without a
dedicated artboard. Full-product Figma expansion remains incomplete; foundation
explorations do not independently specify every viewport, theme or application state.
The application therefore derives those visual treatments from the shared system
while preserving verified behavior. These boards are not a pixel-perfect
full-product implementation contract.

Application behavior remains governed by the verified ClassLink product
documentation linked above, including its routes, permissions, APIs, and
authorization.

All five current PNGs have been opened and validated. See
[`FIGMA_DESIGN_IMPLEMENTATION_MAP.md`](../../docs/FIGMA_DESIGN_IMPLEMENTATION_MAP.md)
for component/route mapping and
[`FIGMA_IMPLEMENTATION_REPORT.md`](../../docs/FIGMA_IMPLEMENTATION_REPORT.md) for
implementation scope, verification and remaining differences.

## Exports and data handling

Future approved exports belong in [`exports/`](exports/).

Never store secrets, credentials, real student data, database files, or private
screenshots in this directory or its subdirectories.
