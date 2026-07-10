# مبادئ تجربة الاستخدام — UX Principles

Applied throughout the `/manage` admin panel and the public site. Every new screen is reviewed
against this list; if a change doesn't make the product nicer to *use*, it isn't done.

## North star

**Frequency drives prominence.** The daily action (find page → edit → save) is one click from
the front door; weekly tasks (users, tutors) are one level in; rare config (Telegram/AI settings)
lives at the bottom of the nav. Never give a rare task a slot a frequent task could use — courses
have no top-level nav because they only exist inside tutors.

## Information architecture

- **Workspaces, not edit forms.** A primary object (a Page) is a full page with breadcrumb,
  inline-editable title, and URL-addressable tabs — not a CRUD form. Tab state lives in the query
  string so back/refresh/deep-link work.
- **Creation is two-tier.** Light objects (tutor, course, user, child page) are created in a
  dialog without leaving context; the heavy flow (page content) escalates to the workspace,
  carrying over what was typed.
- **Contextual creation.** Every picker offers inline "+ إنشاء" (e.g. courses inside the tutor
  dialog) so the user never loses their place to create a prerequisite.

## The review checklist

1. **Progressive disclosure** — rare/advanced sections start collapsed (password change, author
   profile, the whole Telegram tab). Open-by-default only if most users change it on first use.
2. **Optimistic UI with a blast-radius rule** — only for cheap, reversible writes (inline title
   rename, drag reorder). Revert + inline error on failure. Never for destructive or queued work.
3. **Destructive-action ladder** — reversible soft-delete → one confirm *with consequence counts*
   ("سيتم حذف N صفحات فرعية أيضًا") and a restorability note; catastrophic force-delete of a
   page that ever had children → typed-name confirm. Never a bare "هل أنت متأكد؟".
4. **Toast discipline** — no toast when the result is visible where the user is looking (row
   added, order changed). Toasts only for async/cross-context outcomes (cache cleared, settings
   saved). Errors prefer inline placement at the control.
5. **Disabled-with-reason** — a disabled control always says why (title/tooltip: "لا يمكنك حذف
   حسابك", save buttons disabled-until-dirty with a reason). Dead buttons are banned.
6. **Skeletons over spinners** — deferred props (dashboard charts/lists, trash section) render
   layout-matched pulsing skeletons. In-button spinners appear only after ~300ms (no flash).
7. **Empty states that teach** — every list/tab explains in one line why it exists + a primary
   CTA (shared `EmptyState`).
8. **Readiness, surfaced** — a page with no content shows a "بلا محتوى" chip in the tree, not a
   surprise at view time. Status chips appear only when a value is off-default.
9. **One status vocabulary** — badges and event colors come from shared components/tokens
   (`EventBadge`, badge variants); ad-hoc status styling is banned.
10. **Explicit save vs autosave, never mixed** — artifact/publish surfaces (page content,
    settings tabs) use explicit save with dirty-guarded navigation; cheap toggles in lists apply
    immediately.
11. **Polling etiquette** — index freshness via `usePoll` with partial reloads (updates in place,
    page never jumps, pauses in hidden tabs).
12. **Keyboard access** — everything drag-and-drop has a keyboard fallback (up/down actions);
    dialogs/menus follow reka-ui focus management.

## RTL / Arabic (direction-native, not mirrored as an afterthought)

- Logical CSS properties **only** in new code: `ms-/me-/ps-/pe-/start-/end-/text-start`.
  Physical `ml-/mr-/pl-/pr-/left-/right-` are banned (grep for them in review).
- LTR islands for machine text: emails, URLs, slugs, IDs, model names, numbers — wrapped with
  `dir="ltr"` + `text-start`; numeric columns use `tabular-nums`.
- Directional icons (chevrons) mirror with direction; logos/checkmarks don't. Charts flow
  right→left (time axis starts at the right).
- All user-facing strings are Arabic, hardcoded in components (site convention — one language).
- Fonts are self-hosted (Cairo variable font, Arabic subset preloaded); no CDN dependency.

## Visual system

One token source: `resources/css/app.css` (`@theme` + shadcn CSS variables, teal primary,
`--chart-*` for data). **Never hardcode colors in components.** Dark mode is class-based and
default-on; every screen must hold up in both modes. Consistent radius/shadow via tokens;
`.typography` prose styles make the editor WYSIWYG match the public render.

## Mobile

Tables/lists degrade to stacked cards; dialogs stay small and scrollable; primary actions are
reachable; targets ≥ 44px. Daily flows must be usable at ~390px.
