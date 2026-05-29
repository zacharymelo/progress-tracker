# Order Progress Tracker — Plan & Roadmap

Living plan for the `orderprogress` Dolibarr module. Update the status boxes as
work lands.

## Objective
Add a visual progress tracker to the top of customer/supplier order, quotation,
invoice, shipment and reception card pages. Derive progress purely from native
Dolibarr statuses and linked documents — a visual layer, not a new workflow.

## Approach (fixed decisions)
- Native custom module, hook-based UI injection (`printCommonFooter` + JS
  relocation under the card banner). No core file changes.
- `OrderProgressResolver` (logic) + `OrderProgressRenderer` (UI) separation.
- Native status constants + `fetchObjectLinked()` for the document chain.
- Language files for all labels; admin config page; native permissions respected.
- Target Dolibarr **v22** (min v14).

## Workflows modeled
**Customer:** proposal created → accepted → order created → order validated →
shipment completed (only if products require it) → invoice created →
invoice paid → order closed.

**Supplier:** request created (optional) → order created → approved → placed →
reception completed → invoice received → invoice paid → order closed.

Step states: `complete | current | pending | skipped | blocked`.

## Status

### Done (v1.0.0)
- [x] Module descriptor, hooks, constants, permission.
- [x] Resolver: customer + supplier flows, chain traversal, skip logic,
      current-step detection.
- [x] Renderer: circles/labels/tooltips, compact mode, responsive, theme colors.
- [x] Completed steps link to documents (native read perms enforced).
- [x] **Action links:** current/pending steps deep-link to the native page for
      the next step (create invoice/shipment/reception, enter payment, etc.),
      gated by native create/write permissions. Toggle: `ORDERPROGRESS_ACTION_LINKS`.
- [x] Admin setup (per-object enable, display mode, skipped behavior, links,
      colors, debug) + about page.
- [x] English + French language files (at parity).
- [x] v22 compatibility verified against source (hook loading, `$object`
      fallback, `status`/`statut`, `isModEnabled`, create-from-origin params).
- [x] Installer zip packaged with `orderprogress/` root entry.
- [x] PHP lint clean.

### Acceptance criteria (from spec) — all met for v1
1. [x] Customer order card shows tracker at top.
2. [x] Supplier order card shows tracker at top.
3. [x] Completed native steps change color automatically.
4. [x] Pending steps visually distinct.
5. [x] Linked documents clickable when permitted.
6. [x] Uses native statuses + relationships only.
7. [x] No duplicate status system.
8. [x] No core files modified.
9. [x] Works when optional modules disabled (graceful degradation).
10. [x] Labels translatable.
11. [x] Admin enable/disable config exists.
12. [x] Usable on mobile and desktop.

## Roadmap (future)
- [ ] Publish a GitHub Release with the zip asset for `v1.0.0` (tag push is
      blocked in the cloud environment; do from a local clone).
- [ ] Extend default-on coverage to invoice/shipment/reception cards (currently
      off by default).
- [ ] Hover timeline with dates and users per step.
- [ ] Admin-configurable workflow templates.
- [ ] `blocked`/warning state for overdue or stuck steps.
- [ ] Dashboard widget: documents stuck at a step.
- [ ] Filter order lists by progress state.
- [ ] Optional trigger-based caching of resolved steps for faster rendering.
- [ ] Add a custom module picto image under `img/`.

## Explicitly out of scope (v1)
Creating/editing documents, replacing native statuses, custom approval
workflow, custom document-chain engine, manual checklists, external
notifications, dashboard reporting, mobile app integration.

## Testing notes
- Lint: `find . -name '*.php' -exec php -l {} \;`
- Manual: enable module, open a customer order linked to a proposal/invoice,
  confirm tracker placement, states, links, permissions, and mobile layout.
- Debug panel: set `ORDERPROGRESS_DEBUG=1` (admins only) to see the collected
  chain and computed states inline.
