# مبادئ البنية والكود — Code & Abstraction Principles

Applied throughout the Inertia admin rebuild. Future contributors (human or AI) are held to
these; see also `docs/ux-principles.md` and `docs/admin-rebuild-plan.md`.

## One source of truth per concern

- **Find the existing abstraction and extend it; duplicating a pattern is a bug.** Shared UI
  lives in `resources/js/components/ui/` (shadcn-vue over reka-ui — new primitives are written
  in the same style as their siblings) and `resources/js/components/manage/` (ConfirmDialog,
  EmptyState, PageHeader, Pagination, LineChart, RichContentEditor, EventBadge).
- Shared behavior lives in composables (`useSortableList`, `useColorMode`) and
  `resources/js/lib/` (`cn()`, `formatters.ts` — all Arabic number/date/relative-time formatting
  goes through Intl helpers there, never inline).
- The litmus test for new code: *could another screen reuse this?* → it belongs in the shared
  layer. *Did I just duplicate a query, a validation rule, a badge color, a formatter, a picker?*
  → there is already one source of truth; use it.

## Backend shape

- **Thin controllers** in `app/Http/Controllers/Manage/` — map/serialize and delegate. Domain
  behavior stays on models/services where it already lives.
- **Form Requests for every write** (`app/Http/Requests/Manage/`) with Arabic messages;
  cross-field rules in `after()` hooks (parent-cycle checks, self-role protection).
- **Authorization**: `manage.access` middleware gates the panel; per-area `can:` middleware
  groups in `routes/manage.php` (`manage-users`, `manage-private-tutors`,
  `view-activity-logs`). Server enforces what the client hides.
- **All writes via Eloquent so model events fire.** The `booted()` hooks on `Page`,
  `PrivateTutor*` flush public caches (navigation/search/response/screenshots) — this is a
  frozen contract. Concretely: never `DB::` writes, never Spatie `setNewOrder()` (bulk
  query-builder update that bypasses events) — reorder by saving each model.
- **Partial update endpoints**: one `PUT` per resource accepting `sometimes` payloads, so each
  workspace tab saves only its fields.
- Heavy/secondary payloads ship as **deferred Inertia props** (`Inertia::defer`) with skeleton
  fallbacks client-side; index freshness via `usePoll` partial reloads.

## Frozen contracts (rebuild around, never through)

- `pages.html_content` TipTap JSON — custom blocks persist as `type: "customBlock"` with
  `attrs.id` (`alert`/`collapsible`) and rich content as **HTML strings inside `attrs.config`**.
  The editor (`components/manage/editor/`) extends the render extensions in
  `resources/js/tiptap/extensions/` — same node names/attrs/schema — and its contract tests
  (`contract.test.ts`) assert byte-for-byte round-trips. Consumers: public
  `RichContentRenderer` + the bot's `TipTapContentExtractor`.
- `quick_response_*` columns (Telegram bot), public URLs/slugs, `/_og-image/*`, sitemap/robots,
  and the entire Telegram bot (`app/Services/Telegram/**`) — untouched by admin work.
- Legacy HTML-string pages are never silently converted; the editor shows them read-only with an
  explicit convert action.

## Testing

- **Characterization first**: before changing shared behavior, pin it with a test against the
  unmodified code (`PageModelTest`, `PublicRoutesTest`).
- Every admin area ships a Pest feature suite: authorization matrix (guest / role-less /
  editor / admin), CRUD happy + failure paths asserting the Arabic messages, and cache-flush
  smoke tests on write paths. Factories for every model used in tests.
- Pure frontend logic (calculators, editor contract, composables) is vitest — `*.test.ts` next
  to the source.
- Gates before every commit: `php artisan test`, `npm test`, `npm run build:ssr`,
  `vue-tsc` (no new errors vs baseline), eslint/prettier/pint. No red commits.

## Conventions

- PHP 8.4 / Laravel 12 style per `CLAUDE.md`: constructor promotion, explicit return types,
  `casts()` method, PHPDoc over inline comments, Pint before finalizing.
- Vue: `<script setup lang="ts">`, types per feature in a local `types.ts`, props/emit
  interfaces explicit, no new npm/composer dependencies without approval (the charts are
  hand-rolled SVG for exactly this reason).
- Strings hardcoded Arabic; logical CSS properties only; tokens over hex — see
  `docs/ux-principles.md`.
