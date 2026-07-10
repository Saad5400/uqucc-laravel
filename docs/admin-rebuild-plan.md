# خطة إعادة بناء لوحة الإدارة — Filament → Inertia + Vue

> Living plan and inventory. Update status here as phases land. Source of truth for the migration.

## Goal

Remove Filament entirely and rebuild the admin panel on the same stack as the public site
(Inertia v2 + Vue 3 + Tailwind v4 + reka-ui/shadcn-vue), with a major UX upgrade — the result
must be *better to use* than the Filament panel, not merely equivalent.

## Decisions (locked 2026-07-10)

1. **Scope**: admin rebuild (phases 0–5) + a final public-site polish pass (phase 6). No structural redesign of the public site; public URLs frozen for SEO.
2. **Cutover**: parallel build at `/manage` with its own login while Filament keeps serving `/admin`. At parity (phase 5) Filament is removed and `/admin` redirects to `/manage` (or `/manage` moves to `/admin`).
3. **Language**: Arabic-only, RTL, strings hardcoded in components (matches existing app convention). Direction-native: logical CSS properties only (`ms/me/ps/pe/start/end`) in all new code.

## Frozen contracts — rebuild around, never through

- **`pages.html_content` TipTap JSON** — read by the public `RichContentRenderer` *and* the bot's `TipTapContentExtractor`. The new editor must emit compatible JSON, including the custom `alert` and `collapsible` blocks.
- **`quick_response_*` columns** — consumed by the Telegram bot; semantics unchanged.
- **`Page::booted()` cache invalidation** (navigation/search/quick-response/response-cache/screenshot flushes) — must keep firing on every admin save. Lives on the model, survives Filament.
- **Public URLs**: `/{slug}`, `/adwat/*`, `/_og-image/*`, `robots.txt`, `sitemap.xml`.
- **Telegram bot** (`app/Services/Telegram/**`, `RunTelegramBot`, jobs) — do not touch.
- Known couplings to unwind *at the right phase*: `Page.php` and `PageController.php` import `PageResource` (admin edit URLs — rewire in phase 3); `User implements FilamentUser` (remove in phase 5); `filament:optimize` in nixpacks + `filament:upgrade` in composer scripts (phase 5).

## Production reality (uqucc.sb.sa, checked 2026-07-10)

107 public pages, 7 root sections, up to 4 levels deep. Content is mostly prose + lists + links;
tables/alerts/collapsibles appear on regulation pages. The daily admin loop is: find page in tree →
edit rich content → save (caches flush automatically). Page tree management is the core workflow;
users/tutors/settings are rare tasks.

## Phases (each leaves the app bootable; commit per phase)

- [x] **0 — Foundation.** *(done — DataTable deliberately deferred to phase 1 so it's extracted from a real consumer. Baseline: 27 vue-tsc errors + 15 eslint errors, all pre-existing in public/tiptap files, cleanup in phase 6.)* Characterization tests for frozen contracts (Pest). Admin auth from scratch: `/manage` route group, login page + session controller + rate limiting, `role:admin|editor` middleware. Admin shell: sidebar layout, breadcrumbs, command palette, dark/light. Shared component layer: DataTable (TanStack), FormDialog, ConfirmDialog (with consequence counts), StatusBadge, EmptyState, toast-from-flash bridge, formatters. Self-host Cairo font.
- [x] **1 — Prove the layer.** *(done — single `/manage/tutors` workspace with tutors/courses tabs (tab in URL query), dialog CRUD, inline course create, drag + keyboard reorder via reusable `useSortableList` composable, `/manage/settings` Telegram card on new ui/tags-input + ui/switch primitives. Reorder saves each model via Eloquent instead of Spatie `setNewOrder()` so the `booted()` cache flush keeps firing. Sidebar now filters nav items by permission.)* Private tutors + courses (reorderable lists, attach/detach many-to-many), Telegram settings page.
- [x] **2 — Users & roles.** *(done — dialog CRUD with collapsed password/author-profile sections, roles gated on `assign-roles` client- and server-side, verified-email switch, self-delete and own-admin-role removal blocked server-side with disabled-with-reason UI.)* Permission-gated CRUD (`manage-users`, `assign-roles`), password change flow.
- [x] **3 — Pages.** *(done — editor integrated into the page workspace; `quick_response_message` kept HTML end-to-end; `TrimStrings` exemption added so TipTap content round-trips byte-for-byte; `EditLinkHandler` + public `edit_url` rewired from `PageResource` to `/manage`.)* Tree-first list (replaces the 15-filter table), TipTap Vue editor with custom blocks (frozen JSON contract), quick-response section behind progressive disclosure, uploads, soft-delete/restore ladder, children + pivot-ordered authors. Rewire bot/controller edit-URLs from `PageResource` to `/manage`.
- [x] **4 — Dashboard + activity log.** *(done — `DashboardController` shares 6 immediate stat tiles (page/contributor totals + 30-day views/visitors/bot usage + all-time top command) and defers charts/lists via `Inertia::defer` with skeleton fallbacks; charts are a hand-rolled RTL-native SVG `LineChart.vue` (no chart dependency, `--chart-*` tokens). Cache-clear is `POST /manage/cache/clear` behind a ConfirmDialog. `/manage/activity` (gated `can:view-activity-logs`) is server-paginated (25/page) with log/event/subject-type selects in the URL, expandable rows showing an old/new diff table + raw-JSON collapsible, and a generic `components/manage/Pagination.vue`. New `PageViewStat`/`BotCommandStat` factories + `lib/formatters.ts` (Intl-based Arabic relative time/dates).)* Frequency-driven overview (not 9 equal widgets), 30-day charts, cache-clear action, activity viewer with old/new diff.
- [x] **5 — Cutover.** *(done — the AI workstream's four Filament surfaces were ported first: `ManageAiSettings` → an explicit-save card on `/manage/settings` (`PUT /manage/settings/ai`); `CorpusDocumentResource` → `/manage/corpus` (paginated index with status filter/search + 15s `usePoll`, upload dialog, edit workspace with the markdown textarea, re-extract/re-ingest/delete behind ConfirmDialogs dispatching the same jobs — the Filament preview modal was folded into the edit page); `TelegramChatSettingResource` → its own `/manage/telegram-chats` page (list-only resource: inline toggle, reset-conversation, delete); `PageCopilotActions` → three JSON endpoints `POST /manage/pages/{page}/copilot/{improve-text,draft-section,seo-meta}` + buttons in the page workspace (content tab dialogs fill the editor, SEO button fills the quick-response message; hidden entirely and 403'd server-side while `admin_copilot` is off — seo-meta reads the SAVED page content, a deliberate deviation from Filament's live-form read). Then Filament was deleted: `app/Filament/**`, `AdminPanelProvider`, `FilamentUser` on User, 2 composer requires (31 lock packages dropped; `ueberdosis/tiptap-php` promoted to a direct require since `TipTapContent` uses it and only filament/forms pulled it), `filament:upgrade`/`filament:optimize` build steps, published filament assets/views, the `/filament` robots lines. `/admin` and `/admin/{any}` now 301 to `/manage` ahead of the catch-all. Wayfinder regenerated (Filament/Livewire TS stubs gone; vue-tsc 16 → 4, all in `DocsNavbar.vue`). Tests translated, not deleted: `AiSettingsTest`'s Filament describe → the `/manage/settings/ai` endpoint, `PageCopilotResourceTest` → the copilot endpoints, `CorpusDocumentResourceTest` → `/manage/corpus`, plus a new `Manage/TelegramChatsTest`. Full run: 524 passed.)* Remove `app/Filament/**`, panel provider, composer packages, nixpacks/composer Filament steps, `FilamentUser` on User. Port the concurrent AI workstream's Filament surfaces to `/manage` before deleting. Redirect `/admin`. Regenerate Wayfinder. Full gate run.
- [x] **6 — Public polish pass.** *(done — RTL sweep converted every physical utility in `pages/tools/`, `components/page|prose/`, `tiptap/views/` and the shared `ui/` primitives (select, sheet close, sidebar inset/badges/actions/sub) to logical equivalents; side-parameterized shadcn internals (`Sidebar`/`SidebarRail`/`SheetContent` side variants, dialog centering math) deliberately stay physical. Tool pages rewired to the pinned `lib/calculators/*` functions (inline duplicates of `parseArabicNumber`/GPA/transfer/deprivation/reward logic removed), numeric inputs got `inputmode` + Arabic-Indic digit support, results are `tabular-nums` LTR islands, empty/initial states added, GPA rows/buttons no longer overflow at ~390px. Baseline debt cleared: eslint 15→1 and vue-tsc 27→16 — the remainder live only in `Welcome.vue`, `components/layout/DocsNavbar.vue`, and Wayfinder-generated `resources/js/actions/**`. Notes: the search/palette item moved to the concurrent AI workstream (`DocsNavbar`/`AiSearchPalette`), and `Welcome.vue` was kept as the live fallback instead of being removed.)* RTL physical→logical utility sweep, tools index upgrade, search/palette, mobile ergonomics audit, remove dead `Welcome.vue`. Write `docs/ux-principles.md` + `docs/code-principles.md`, reference from README + CLAUDE.md.

## Verification gates (every phase, no red commits)

```
npm run build:ssr        # clean build
npx vue-tsc --noEmit     # no new type errors vs baseline
npm run lint && npm run format:check
npm test                 # vitest (calculators/transcript must stay green)
vendor/bin/pint --dirty
php artisan test         # Pest, incl. new characterization + feature tests
```

## Admin inventory (what must exist at parity — ranked by complexity)

1. **Pages** (high): reactive form, JSON rich editor + alert/collapsible blocks, Telegram quick-response repeater + multi-file upload, ~15 list filters incl. root-only default, drag-reorder (spatie sortable, per-parent), soft deletes + restore, children manager, authors manager (pivot `order`), 30s poll.
2. **Activity log** (medium): read-only, filters (log/event/subject), old/new diff view.
3. **Users** (medium): CRUD gated on `manage-users`, reactive password change, roles select gated on `assign-roles`, `telegram_id` read-only.
4. **Private tutors / courses** (low): name+url / name forms, reorderable, mutual attach/detach.
5. **Telegram settings** (low): allowed chat IDs (tags input) + auto-delete toggle over `TelegramSettings`.
6. **Dashboard** (volume): page/view/bot-command stat groups, 2 line charts (30d), latest pages, most-viewed, top commands, cache-clear action → redesign into one focused overview.

Auth model: roles `admin` (all perms) / `editor` (`edit-content`, `view-activity-logs`); permissions
`manage-users`, `assign-roles`, `edit-content`, `manage-private-tutors`, `view-activity-logs`.
Pages/tutors CRUD is currently un-gated beyond panel access for editors — keep that behavior.
