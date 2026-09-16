# UI modernization: implementation and validation

Review branch: `codex/ui-modernization`, based on the latest user commit `7b26b50624934dd2ac143aea0b843568691a91d3`.

**Status, 15 September 2026:** the corrective Jobs and Memories work is implemented with a disposable PHP fixture and automated interaction checks. Visual browser validation, before/after screenshots, performance measurements, and live service integration are still outstanding. This is not a claim that every state in the [UI inventory](ui-modernization-plan.md) has been exercised.

**What changed**

| Area | Implemented behavior |
| --- | --- |
| Shared appearance and controls | Navy/cyan palette, readable text, common buttons/spinners/notices, selection and keyboard focus styles, labelled navigation, responsive panes, reduced motion, and fewer continuous effects. |
| Chat navigation | Immediate selection feedback, cancellable history reads, protection against stale responses, six retained conversation DOM entries, per-conversation drafts, and background stream isolation. Ordinary switching uses a narrow history response instead of rebuilding the full page. |
| AI availability and composer | Shared checking/ready/busy/offline/unknown states, current-task links, explicit busy rejection, draft recovery, and Stop attached to the generating conversation. No new queue or automatic resubmission. |
| Reply feedback | Helpful/unhelpful states, required downvote reasons, tool-specific reason filtering, cancellation, saved reason labels, duplicate-submit protection, and read-back after ambiguous failures. |
| Context Data | A coordinated evidence inspector, source links, clear full-evidence/key-facts/excluded labels, editable extraction previews, explicit commit/cancel, restore/exclude controls, and guards for pending or unsaved work. Background warnings stay associated with their conversation. |
| Document editor | Resume/discard/save actions, ordered draft writes, retained failed edits, file ownership checks, and confirmed suggestion application. An older failed edit cannot overwrite a newer queued edit on retry. |
| Jobs | Results and readable job details stay in one padded workspace with stage navigation, a searchable list pane, and an independently scrollable detail/activity pane. Search setup combines CVs, preferences, and sources; progress and history appear in Search activity. Selection, drafts, pending operations, cancellation, lost connections, and application recording have explicit states. |
| Memories and consolidation | Memories stay beside the chat in a readable vertical inspector with visible edit/delete actions, selection counts, bulk delete, and add-memory controls. Consolidation uses a review dialog, preserves dirty drafts, prevents duplicate submissions, distinguishes a busy model from an ambiguous write, and offers an explicit read-back before retrying. |
| Other workspaces | Consistent styling and clearer actions across files, memories, email, settings, onboarding, model statistics, and event logs. Email suggestions preserve the draft and require an explicit send. File deletion retains failed selections when only some files were deleted. |
| Supporting PHP | Read-only availability, conversation, and rating endpoints; atomic lock/status lookup using the existing ModelLock service; typed busy responses; confirmed deleted file IDs. Existing persistence, inference, and job transition rules are reused. |

The detailed element-by-element scope remains in [ui-modernization-plan.md](ui-modernization-plan.md). Shared styles apply to both server-rendered and JavaScript-created controls.

**Checks completed**

| Check | Result and limit |
| --- | --- |
| `npm run test:ui` | 24 passing DOM/state tests with the actual ES modules and PHP template fixture. Covers rapid chat/job navigation, per-chat drafts, background output/evidence/ratings, background context warnings, partial stream errors, busy cleanup, Stop ownership, offline versus unknown, rating reasons and ambiguous saves, CV selection, Jobs setup/activity, context previews, serialized document edits, and the Memories/consolidation running, draft-guard, selection, and read-back states. |
| `php tests/ui/backend.php` | 43 existing deterministic assertions pass: 16 reply-rating cases and 27 job-state cases. Does not exercise the new endpoints against MySQL or Redis. |
| `npm run check:ui` | JavaScript module syntax, PHP source/fixture syntax, and rendered inline JavaScript pass. The template test also checks unique IDs, primary control labels, and the compiled stylesheet reference. |
| `npx esbuild tests/ui/entry.js --bundle --format=esm --outfile=/tmp/localsy-ui-check.js` | All application entry-point imports resolve. This temporary bundle is a check, not a production asset. |
| `npm run build:css` | Static Tailwind build succeeds. `src/css/utilities.css` is committed and loaded by the application and diagnostic pages. |
| Whitespace review | Passes with `git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --check`. Existing CRLF line endings are preserved to keep the source diff readable. |

Tests used PHP 8.3.6 and the pinned Node development dependencies. No private environment file or live user data was loaded. The PHP fixture renders the real shell and templates with synthetic data; jsdom does not perform layout, painting, accessibility-tree, or performance validation. These tests do not replace a live browser review.

The browser check was blocked: local Chromium could not create the required sockets, and the cloud browser policy rejected local preview files. No screenshots or browser timing results were produced. MySQL, Redis, the launcher/llama service, and IMAP/SMTP were unavailable for integration tests. The full repository test suite was not run.

**Reproduce the automated checks**

Use Node 22+ and PHP 8.3. From the repository root:

```sh
npm ci
npm run build:css
npm run check:ui
npm run test:ui
php tests/ui/backend.php
```

If PHP is not on PATH, set `LOCALSY_PHP` to its executable for `check:ui` and `test:ui`, and use that executable for `backend.php`.

Node is only needed for development/build checks. The current Dockerfile copies `src`, including the compiled CSS; the development compose bind mount serves the same file. Rebuild and commit `src/css/utilities.css` after changing utility classes in PHP or JavaScript. No Node process is added to the application runtime.

For a fixture-only browser review in an environment that permits local previews, the real shell and templates can be exercised with synthetic Jobs and Memories data:

```sh
php -S 127.0.0.1:8081 -t src tests/ui/preview.php
```

Open `/?session_id=3&tab=jobs&fixture=populated` for the populated Jobs workspace, `/?session_id=3&tab=memories&fixture=populated` for the Memories inspector, or use `fixture=empty`, `fixture=busy`, and `fixture=error` to review empty, model-busy, and failed-service states. Add `reset_fixture=1` when you want to reset the disposable session data. The fixture is review-only and is not included in the production bootstrap.

The optional `tests/ui/capture.mjs` helper captures chat and Jobs fixtures with Playwright. It was not successfully run here. Fixtures do not simulate the complete application or every write endpoint; exercise those actions in the normal development stack with disposable test data.

**Review still required before merge**

| Review | Scenarios and expected result |
| --- | --- |
| Layout and legibility | Inspect chat, context, editor, Jobs results/setup/activity, email, files, memories, settings, onboarding, and diagnostics at 1366×768, 1920×1080, a narrow/touch viewport, and 200% zoom. Long titles, filenames, code, tables, and source lists must keep controls reachable. Capture before/after screenshots on the same fixture/workload. |
| Accessibility | Keyboard-only traversal, visible focus, names and states, dialog focus/return/Escape, sidebar and inspector behavior, touch targets, reduced motion, and measured contrast. The DOM tests cover only selected attributes and focus behavior. |
| Chat and inference | Real navigation during streaming, rapid selection, Back/Forward/modifier clicks, new-session creation, Send/Stop, pre-start and mid-task errors, attachments, and context limits. Verify the read endpoints with MySQL and Redis; compare availability with the real lock, including an external task and service failure. |
| Feedback, context, documents | Vote/reason save/clear/reload and interrupted responses; extract/edit/commit/cancel/restore evidence; pending and dirty-panel guards; multi-block editing, save/discard, reopen, and network failure. Confirm persistence and preservation of source material. |
| Jobs | Upload/default/select/extract/delete CV, saved preferences and sources, each legal individual/batch transition, application CV/date recording, blocks/restore/delete, counts/pagination, search progress, cancellation, connection reconciliation, and run logs. Verify against real service responses. |
| Email and files | Account/thread changes with drafts, suggestion completion after navigation, explicit Send and ambiguous send failures, file uploads, sync, previews, and partial deletion. Use test accounts/files. |
| Performance | Measure click-to-feedback, request duration, history rendering, streaming, idle work, and panel transitions against the base revision. Static CSS and lighter read paths are implementation changes; no measured speed-up is claimed. |

The PR should remain a draft until these outstanding checks are completed and any resulting issues are resolved. Merge and deployment have not been performed.
