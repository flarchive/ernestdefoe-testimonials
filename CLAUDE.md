# Testimonials: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center for review first. New extension: dev + ernestdefoe.dev only until Ernest has tested it (decision 6: it then goes on ernestdefoe.online too).
- Scope and Ernest's decisions: `~/github/command-center/scopes/testimonials.md`. Mockup: https://claude.ai/artifact/DXMtzyWnJSv9TLwbDBegTT

## Design rules that exist for a reason

- **One endpoint, one rule.** The page, queue, profile and every widget read `GET /api/testimonials` (`Api\TestimonialResource`); visibility is `Scope\Visibility` (moderators all; the author their own at any stage; everyone else live ones about a subject they may see: tag visibility, post visibility, Depot's `visibleTo`).
- **🚨 Bylines are enforced in `Presenter::author`, never in the browser.** Anonymous: no name, picture, username, id or join year; first name: the first word only, no picture or username. A profile list (`filter[user]`) only ever lists `byline = full` to others. `tests/…/an_anonymous_testimonial_names_nobody_in_the_api` greps the raw JSON.
- **🚨 Raw SQL gets no table prefix**: every column in `selectRaw`/`orderByRaw` goes through the grammar (`Summary::col`, the featured sort). SQLite tests have no prefix and passed with the bug; the `dev_`-prefixed MySQL run caught it. Run all three drivers.
- **N+1**: subjects (`Subjects::prime`) and aspect ratings (`Presenter::prime`) load for a whole page in one query each, primed in `results()`; users are eager-loaded in `scope()`. The test `the_list_costs_the_same_queries_for_one_or_twenty_four` holds it. The presenter re-reads on every prime (a resource can outlive a request in a worker).
- **Summary cache**: guests only, one entry per subject overwritten in place (`{v, data}`), keyed on a version bumped by `Moderation::changed()` (every write, any settings save). Never a key per version: the file cache never deletes expired entries.
- **Text is plain** (`Writer::body`: tags stripped, entities decoded, whitespace folded). Emails swap the decline reason in escaped, after Markdown.
- **Depot reviews are mirrored** (`Depot\Mirror`, source `depot`) on `eloquent.saved/deleted` of `ErnestDefoe\Depot\Model\Review`, plus `testimonials:sync-depot` daily. Only 4★+ with words (Command Center 2026-11-12: reviewers never consented to a testimonial): forum only, `consent_external` always false, gone when edited below 4★ or deleted; the author can hide theirs from the widgets; the admin switch filters them out in `Testimonial::onlyLive`.
- **Renown** (1.2.0, ships with Testimonials 1.0): Testimonials fires `Event\Approved` / `Event\Withdrawn`; Renown listens (its own integration): XP once per testimonial and per member + subject, soft revoke on withdraw (hard when the model is deleted), Depot mirrors earn nothing. 🚨 **Contract:** Renown also reads our `testimonials` table, `status = 'approved'` and `hidden_by_author = false`, to hand the award to the member's other live native testimonial on the same subject. Don't change those two columns' meaning (or the events' timing) without telling the Renown session.
- **Widgets**: one component (`widgets/Widget.tsx`) for all four kinds and all hosts; data in a module-level store (`widgets/data.ts`: Bespoke re-creates widgets every redraw); the carousel is driven from the DOM by one ticker; compact/narrow by a single ResizeObserver. Stock spots stand down only for a *mounted* copy of the same kind (`placement.ts`). FoF widgets are opt-in (`placed()` + admin `registerEditorWidgets`). Bespoke labels are keys. Page Builder: PHP blocks in `src/Widget` (resolve returns nothing) + the client components pushed onto `window.PageBuilderBlockQueue` from our initializer (Page Builder 3.10+ drains it on every lookup, so load order doesn't matter), with a direct `app.pageBuilder.registerBlock` as well for Page Builder before 3.10.
- **A plain function in JSX is a closure component factory in Mithril**: small views (`Stars`, `Card`, `Controls`…) are wrapped as `{ view }` objects.
- **Flarum 2's `.Form-group` is a centred flex column**: the form stretches its groups (`.ShareTestimonial .Form-group`).
- extend.php uses string literals for setting keys. Lazy chunk: one entry (`components/index.ts`), published by `jsDirectory`.

## Testing

- `composer test:setup && composer test` (SQLite), then `~/github/scopes/testimonials/dbtests.sh` (MySQL `dev_` prefix + PostgreSQL in Docker; it prints the driver from the test config so a "MySQL" run is really MySQL). `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum `~/Herd/tmdev` (https://tmdev.test, MySQL `tmdev`, prefix `lr_`, Bespoke/Page Builder/FoF Widgets/Depot). Seed: `php ~/github/scopes/testimonials/seed.php`. Shots: `cd ~/github/scopes/testimonials && node shots.mjs [page|queue|profile|index|pb|share]`.
