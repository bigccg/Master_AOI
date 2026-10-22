# Zone & Sheet manager: components and rules

For developers who change the manager screens, the sign-in page or the zone / sheet rules.
There was no `zone-selector-design-system.md` to extend, so this file starts the component notes.
(`docs/` is blocked from the web by `docs/.htaccess` and by the `index.php` router.)

## Data

| Table | Key | Notes |
|---|---|---|
| `zones` | `zone_key` (letters, digits, `_`, 1–30 characters, at least one letter; unique ignoring case; never changes) | `label`, `short_label`, `sort_order`, `is_active`. `DEFAULT_ZONE` (General) can never be disabled or deleted. |
| `sheets` | `sheet_key` (same format, not `ALL`, unique system-wide) | `zone_key` (FK → zones), `shelf_prefix` (`[A-Z]{1,3}`, unique, never changes), `dot_color` (one of `SHEET_DOT_PALETTE`), `sort_order`, `is_active` |
| `topology_audit` | `id` | One row per successful write: `action` (create / update / disable / enable / move / delete), `entity_type`, `entity_key`, `before_json`, `after_json`, `ip`, `created_at` |

- `seedTopology()` copies the deprecated constants (`ZONES`, `ZONE_META`, `SHEETS`, `SHELF_PREFIX`) into the tables **once**, while `zones` is empty. Because General can never be deleted, `zones` never becomes empty again, so later runs never re-seed or overwrite anything.
- Read everything through `topology()` and the helpers in `config/database.php`. Nothing else may read the constants.
- A **disabled** zone or sheet keeps its records, and direct links, search and export still work for it. It is left out of the zone selector and the tabs, and it takes no new records or Magazines. A **deleted** key no longer exists, so links to it fall back as before (zone → General, sheet → `ALL`).
- Moving a sheet that already has records is refused. If that is ever needed: back up first, then run `UPDATE boards SET zone = '<new zone>' WHERE sheet = '<sheet>'`, change `sheets.zone_key`, run the UPDATE again, and confirm that section [2] of `php scripts/check-duplicate-codes.php` reports "None found".

## Rules (the server checks all of them; disabled buttons in the UI show the same reason)

| Action | Allowed when |
|---|---|
| Add zone | The key is valid and free (ignoring case) and the label is not empty. The zone starts without sheets and shows the existing "no sheets" empty state. |
| Edit zone | Only `label`, `short_label`, `sort_order` and `is_active` can change. |
| Disable zone | It is not General, and none of its sheets is enabled. |
| Delete zone | It is not General, it has no sheets, and no board record is stored with this zone. |
| Add sheet | The zone is enabled, key and prefix are valid and free across the system, and the colour comes from the palette. The sheet starts with 1 Magazine. |
| Edit sheet | Only `label`, `dot_color`, `sort_order` and `is_active` can change. Enabling a sheet requires an enabled zone. |
| Move sheet | The sheet has no records, and the target zone is enabled. |
| Delete sheet | The sheet has no records. Otherwise the user is offered "disable" instead. |

## Components (reuse; no new colours or spacing)

| Component | Built from | New CSS (`components/topology-manager.css`) |
|---|---|---|
| Manager window `#topology-manager` | `gm-overlay`, `gm-window`, `gm-head`, `gm-form` / `gm-body`, `gm-actions`, `gm-status` | `.tm-subtitle`, `#tm-body.is-busy` |
| Zone group / sheet row | Table-row focus style (`#10b981` outline, `#f0fdf4`), the `tab-dot` from the sheet tabs | `.tm-zone`, `.tm-row`, `.tm-chip.is-on / is-off / is-default` (emerald / slate / blue tokens) |
| Row action button | The filter bar's square icon buttons (`--ctl-h`, 8px radius, red-200 danger border) | `.tm-icon-btn`, `[aria-disabled="true"]` (stays focusable; tooltip + click show the reason) |
| Form fields | `gm-field`, `gm-label .k`, `gm-input(.mono)`, `gm-hint`, `gm-error`, glass `<select>` (selectors now `:is(#add-entry-modal, #topology-manager)`) | `.tm-keep-case` (keys keep their case), `[aria-invalid="true"]` (rose ring) |
| Colour swatches | Native radio group (arrow keys), palette `SHEET_DOT_PALETTE` | `.tm-swatch`, checked ring + ✓ like `gm-seg-item` |
| Shelf / tab preview | `gm-shelf-pill`, the active-tab look | `.tm-preview`, `.tm-tab-preview` |
| Warnings / notices | Amber pill tokens (`#fffbeb` / `#fcd34d` / `#92400e`) | `.tm-warning`, `.tm-notice` |
| Confirm popup `#topology-confirm` | Same markup as `delete-confirm` (`gm-window sm is-danger`, `gm-pulse`, `gm-confirm-*`). Cancel has focus, Esc cancels. | none |
| Sign-in page `admin.php` | `gm-overlay` as page background + `gm-window sm` form | `.tm-lead`, `.tm-steps`, `.tm-code` |

States: hover / focus-visible use the emerald outline or ring. Active presses get `scale(.96)` from the theme. Disabled controls use `aria-disabled` plus a reason (`title`, a hidden description and an inline notice when clicked). Loading shows the table's spinner state. Saving puts a spinner in the button and dims the list (`.is-busy`). Errors appear inline under the field and in `gm-error`, plus a toast (`showFlash`).

Keyboard: Tab is trapped inside the open window (`gmTrapTab`). ↑ / ↓ / Home / End move between rows (roving tabindex). Enter on a row opens its form, and Enter in a form saves. Esc goes back from a form to the list, then closes the manager. Inside the confirm popup, Esc only closes the popup.

## Copy

Every message follows "what happened + why + how to fix", written `ไทย / English`. Server messages are in `topologyCopy()` (`includes/topology-manager.php`) and are sent to the form as `data.copy`, so the instant checks show the same text the server would. UI labels and confirm texts are in `TM_COPY` (`assets/js/components/topology-manager.js`). Messages for blocked inserts are PHP constants in `config/database.php`, mirrored with identical text in `assets/js/core/messages.js`.
