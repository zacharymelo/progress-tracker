# CLAUDE.md — Order Progress Tracker (`orderprogress`)

Project memory for Claude Code. Keep this concise and current.

## What this is
A Dolibarr **custom module** that renders a read-only visual progress tracker
at the top of order / proposal / invoice / shipment / reception card pages.
It is a **visual layer over Dolibarr's native document chain** — it must never
create a parallel workflow, duplicate native logic, or modify core files.

- Internal name / dir: `orderprogress`
- Display name: "Order Progress Tracker"
- Target platform: **Dolibarr v22** (min supported v14)
- License: GPL-3.0-or-later

## Repo layout
This repo **is** the module (files at repo root). When installed it lives at
`htdocs/custom/orderprogress/`.

```
core/modules/modOrderProgress.class.php   Module descriptor (numero 500120)
class/actions_orderprogress.class.php      Hook handler -> ActionsOrderprogress
class/orderprogressresolver.class.php      Progress logic from native data
class/orderprogressrenderer.class.php      Pure HTML/UI output
admin/setup.php, admin/about.php           Config + about pages
lib/orderprogress.lib.php                  Admin tab head helper
langs/{en_US,fr_FR}/orderprogress.lang     Translatable labels
css/orderprogress.css                      Responsive, theme-aware styles
bin/module_orderprogress-1.0.0.zip         Built installer package (git-tracked)
docs/PLAN.md                               Spec, status, roadmap
```

## Architecture (how it works)
- **Injection via hooks, not core edits.** Descriptor registers the flat
  `module_parts['hooks']` array for contexts: `ordercard`, `ordersuppliercard`,
  `propalcard`, `supplier_proposalcard`, `invoicecard`, `invoicesuppliercard`,
  `receptioncard`, `expeditioncard`. The handler implements **`formObjectOptions`**
  (NOT `printCommonFooter` — Dolibarr v22 calls executeHooks for it but silently
  discards `$hookmanager->resprints`) and a small jQuery snippet relocates the
  rendered tracker to just below the card banner (`div.arearef`).
- **Resolver** walks the chain with `fetchObjectLinked()` (depth 2, cycle-safe),
  collects docs by normalized element type, and emits ordered steps with a
  state (`complete|current|pending|skipped|blocked`).
- **Renderer** is output-only; completed steps link to the document, current/
  pending steps deep-link to the native "next action" page.

## Dolibarr conventions to follow (verified against v22 source)
- **Hook class loading:** module dir `orderprogress` → file
  `class/actions_orderprogress.class.php`, class `ActionsOrderprogress`
  (`Actions` + ucfirst(dir)). Do not rename.
- **`printCommonFooter` does NOT forward `$object`** — read the page object via
  the global (`$GLOBALS['object']`) fallback, which the handler already does.
- **Status property:** prefer `->status`, fall back to deprecated `->statut`
  (use `OrderProgressResolver::statusOf()`). Never assume only one exists.
- **Module enabled check:** use `isModEnabled('expedition')` etc., not
  `$conf->module->enabled`.
- **Permissions:** use `$user->hasRight('module','level1'[,'level2'])`. Supplier
  rights have alternates: `fournisseur/commande/*` or `supplier_order/*`;
  `fournisseur/facture/*` or `supplier_invoice/*`.
- **Permission id:** `numero.sprintf("%02d", $r+1)` (collision-safe).
- **Create-from-origin URL params differ by target:** order/invoice use
  `originid`; **shipment (`expedition/card.php`) and reception use `origin_id`**.
  The resolver passes both to be safe. `action=create` only renders the native
  form (no state change), so GET links are safe.
- All user-facing strings go through `$langs->trans()` and live in lang files.

## Status integer values (native)
- Commande: 0 draft, 1 validated, 2 in-process, 3 closed, -1 canceled.
- CommandeFournisseur: 0 draft, 1 validated, 2 approved, 3 sent, 4 received
  partial, 5 received complete, 6/7 canceled, 9 refused. (Bound predicates to
  2..5 etc. so canceled/refused never count as approved/sent.)
- Propal: 2 signed, 4 billed. Expedition/Reception: 2 = closed/processed.

## Build & release
- Lint everything: `for f in $(find . -name '*.php'); do php -l "$f"; done`
- Package for the web installer (root entry MUST be `orderprogress/`):
  ```bash
  rm -rf /tmp/pkg && mkdir -p /tmp/pkg/orderprogress
  cp -r core class admin langs lib css README.md /tmp/pkg/orderprogress/
  ( cd /tmp/pkg && zip -r -q .../bin/module_orderprogress-<ver>.zip orderprogress )
  ```
  Filename pattern: `module_<name>-<version>.zip`. Dolibarr's
  *Deploy/install external module* UI extracts it into `htdocs/custom/`.
- Bump `version` in the descriptor and the zip filename together.

## Workflow notes
- Develop on branch `claude/dolibarr-order-progress-tracker-fYwXq`.
- This environment's git proxy **rejects tag pushes (403)** and non-allowed
  branches; tags must be pushed from a normal local clone.
- Do not open PRs unless explicitly asked.

## Out of scope (v1)
No object creation/editing, no status replacement, no custom approval engine,
no notifications/dashboards. See `docs/PLAN.md` for the roadmap.
