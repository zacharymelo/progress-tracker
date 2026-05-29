# Order Progress Tracker (`orderprogress`)

A Dolibarr module that adds a **visual progress tracker** to the top of order,
proposal, invoice, shipment and reception card pages.

The tracker shows where a document stands in Dolibarr's **native** business
flow using circular step indicators with text labels. Completed steps change
color, the current step is highlighted, and pending steps stay neutral.

> This is a **read-only visual layer** over Dolibarr's native document chain.
> It does **not** create a parallel workflow, does **not** override native
> behavior, and does **not** modify any core file.

Compatible with **Dolibarr v22** (minimum supported: v14).

---

## What it shows

The steps are derived entirely from native statuses and linked documents.

**Customer flow** (proposal → order → shipment → invoice → payment → closed):

1. Quotation created
2. Quotation accepted
3. Order created
4. Order validated
5. Shipment completed *(only when products require shipment)*
6. Invoice created
7. Invoice paid
8. Order closed

**Supplier flow** (request → order → approval → placement → reception → invoice → payment):

1. Request created *(skipped when not used)*
2. Order created
3. Order approved
4. Order placed
5. Reception completed
6. Invoice received
7. Invoice paid
8. Order closed

Steps adapt to the enabled modules, the object type, document links and
configuration. Not-applicable steps are shown muted (or hidden).

## Step states

| State    | Meaning                                  | Default color |
|----------|------------------------------------------|---------------|
| Complete | The native step has happened             | green         |
| Current  | The document is at this step now         | blue          |
| Pending  | Not done yet                             | grey          |
| Skipped  | Not applicable to this document          | muted grey    |
| Blocked  | Reserved for future warning states       | red           |

## Clickable steps

* **Completed** steps link to the related native document (respecting your
  native read permissions).
* **Current / pending** steps link to the native page where you perform that
  step next — e.g. *create the invoice from the order*, *enter a payment*,
  *create the shipment*. These links only **deep-link to native Dolibarr
  pages** (creation forms seeded by origin, or the document card that holds the
  native button). They never perform an action by themselves and never
  duplicate native logic. Each link is shown only when your native permissions
  allow that action.

Both behaviors can be toggled in the module configuration.

## Installation

This repository is the module itself. Install it into your Dolibarr
`htdocs/custom` directory under the name `orderprogress`:

```
htdocs/custom/orderprogress/   <-- contents of this repository
```

For example:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone <this-repo-url> orderprogress
```

Then enable **Order Progress Tracker** in *Home → Setup → Modules/Applications*
(Interfaces family) and configure it via its setup page.

## Configuration

Admin → Modules → Order Progress Tracker → Setup lets you:

* Enable/disable the tracker per object type (orders, proposals, invoices,
  shipments, receptions — customer and supplier).
* Choose **full** (circles + labels) or **compact** (circles only) display.
* Show skipped steps as *Not applicable* or hide them.
* Toggle links on completed steps and action links on open steps.
* Override colors (hex / CSS name / `rgb()`), or leave empty to use the theme.
* Enable admin-only debug output.

## How it works

* **Hooks**, not core edits. The module registers a hook handler
  (`ActionsOrderprogress`) for the relevant card contexts (`ordercard`,
  `ordersuppliercard`, `propalcard`, `supplier_proposalcard`, `invoicecard`,
  `invoicesuppliercard`, `receptioncard`, `expeditioncard`) and renders during
  `formObjectOptions`. A tiny jQuery snippet relocates the rendered tracker to
  just below the card banner, so no template is patched.
* **Native data only.** `OrderProgressResolver` walks the document chain with
  `fetchObjectLinked()` and reads native status constants
  (`Commande`, `CommandeFournisseur`, `Propal`, `Facture`, `Expedition`,
  `Reception`, …). It prefers the modern `->status` property and falls back to
  the deprecated `->statut` for older cores.
* **UI only.** `OrderProgressRenderer` produces the HTML/CSS; it performs no
  reads or writes.
* **Graceful degradation.** Disabled optional modules simply drop their steps.

## File layout

```
core/modules/modOrderProgress.class.php   Module descriptor
class/actions_orderprogress.class.php      Hook handler (UI injection)
class/orderprogressresolver.class.php      Progress logic (native data)
class/orderprogressrenderer.class.php      HTML renderer
admin/setup.php                            Configuration page
admin/about.php                            About page
lib/orderprogress.lib.php                  Admin tab helper
langs/en_US/orderprogress.lang             English labels
langs/fr_FR/orderprogress.lang             French labels
css/orderprogress.css                      Styles (responsive)
```

## License

GPL-3.0-or-later.
