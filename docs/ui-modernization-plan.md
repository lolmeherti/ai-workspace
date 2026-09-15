# Localsy UI modernization plan

**Status: implementation authorised by your instruction on 14 September 2026.**
Repository: [lolmeherti/ai-workspace](https://github.com/lolmeherti/ai-workspace)
Source reviewed: `master`, revision `a7c9da1b`, 13 September 2026.

The goal is a coherent futuristic interface that is attractive, readable, responsive, and obvious to operate. The scope covers the whole application. Layout and visual styling may change substantially where they currently constrain the content.

Your main concerns are inconsistent controls and highlighting, clutter, weak indications that something is clickable, unclear AI availability, sluggish or missed chat navigation, and an unnecessarily fragmented Jobs workspace. The existing experience generally works for you, but Context Data and Jobs need clearer organisation. This plan covers both visual consistency and the interaction changes needed to address those problems.

This review used repository source. The running application has not yet been visually inspected or performance-profiled here. Code findings below are distinguished from proposed changes and checks.

**1. Visual direction**

Use midnight navy surfaces, crisp light text, and an icy cyan accent. Give the futuristic character to selected edges, active controls, and restrained depth. Most reading surfaces will be opaque, making contrast predictable and avoiding unnecessary background blur.

| Element | Proposed specification |
| --- | --- |
| Backgrounds | Near-black navy canvas, a visibly lighter panel layer, and a distinct elevated layer for menus and dialogs. |
| Accent | Cyan for primary actions and selection; colour paired with shape, text, or an icon when it communicates state. |
| Text | Body text 15–16px; ordinary controls around 14px; supporting metadata at least 12px. Use relative units so zoom and text scaling work. |
| Typography | One readable UI font stack; monospace for code and numerical metadata. Sentence case for normal controls. |
| Spacing | A shared 4/8px spacing scale, typically 16–24px inside panels and enough separation between unrelated controls. |
| Shape | Consistent rounded rectangles: roughly 8–10px for controls and 12–16px for panels. One icon family with consistent sizing and stroke weight. |
| Targets | Usually 36–40px high for desktop controls and at least 44px effective targets on touch. Small icons retain a larger clickable area. |
| Emphasis | One strongest action per local action group. Secondary actions remain clearly visible; destructive actions have distinct placement and labelling. |
| Effects | Subtle stationary depth and brief interaction feedback. Readability and responsiveness govern the amount of glow. |

Normal text will meet a 4.5:1 contrast target; large text may use 3:1. Control outlines and state indicators that are necessary to identify the control or state will target 3:1 against adjacent colours. These checks follow [W3C text contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) and [non-text contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html). They are specific acceptance checks, not a claim of complete accessibility certification.

[Linear’s illustrated redesign](https://linear.app/now/how-we-redesigned-the-linear-ui) is a reference for spacing, alignment, and hierarchy. Localsy will retain its own futuristic colour and surface treatment.

**2. Make interaction apparent throughout the application**

Define shared states for buttons, navigation, selectable rows, disclosures, inputs, and menus. A pointer cursor alone is insufficient; the component must already suggest its purpose before hovering.

Coverage includes small controls and conditional states that appear only during a particular operation. Section 9 lists the proposed treatment of the controls identified in PHP templates, JavaScript-generated markup, event handlers, and supporting screens. During implementation, attach rendered verification results to that inventory and reconcile any repository changes since this review. Reply reactions, downvote reasons, busy notices, warnings, and confirmation flows are explicit deliverables. The user does not need to enumerate them individually.

| Situation | Required user-visible behaviour |
| --- | --- |
| Clickable at rest | A recognisable control, labelled action, or row with an explicit open indicator. Essential actions remain visible. |
| Hover | A noticeable surface or outline change covering the actual target. Passive content does not pretend to be clickable. |
| Keyboard focus | A clear focus ring, distinguishable from selection. Keyboard users can reach and operate every control. |
| Pressed | Immediate feedback without shifting neighbouring content or moving the target away from the pointer. |
| Selected or open | A persistent selected treatment plus an icon or other structural cue; expanded state remains clear after the pointer leaves. |
| Busy | One consistent spinner treatment with a useful status label. Control dimensions remain stable and duplicate submissions are prevented. |
| Disabled | Clearly unavailable, with a visible explanation when the reason is not obvious. |
| Success or failure | Local feedback beside the affected action; failures remain readable and recovery actions remain available. |
| Disclosure | The header is a generous target with a chevron, visible hover/focus states, and matching expand/collapse feedback. |

Use short transitions: approximately 120–160ms for hover/press feedback and 180–220ms for panels and disclosures. Motion must tolerate rapid repeated input and content arriving during expansion. Reduced-motion preferences receive an immediate or minimal-motion equivalent.

Menus and dialogs support keyboard operation, Escape where appropriate, focus restoration, and clear close controls. Busy status announcements remain concise; streaming tokens are not individually announced.

**3. Give the application room to work**

Rebalance the shell around available space rather than shrinking text to fit fixed boxes.

- Replace the cramped five-item navigation strip with clearly labelled workspace navigation. The expanded sidebar will generally occupy 240–300px and be collapsible; the active workspace remains obvious.
- Separate navigation, workspace actions, and status information. Keep system health available in a compact summary with expandable detail.
- Make headers wrap or reorganise before titles and controls collide.
- Give conversation text a readable line length while allowing code, tables, documents, and inspectors more width.
- Adapt mail and job list/detail panes to available width. Narrow windows show a clear list-to-detail navigation path instead of squeezed columns.
- Keep composer, panel headers, and relevant action footers accessible while their content scrolls.
- Ensure browser zoom and short laptop windows can reach every action. Horizontal scrolling is reserved for content that requires it, such as wide tables and code.

**4. Context Data: a spacious inspector**

The principal proposed layout change is to move Context Data out of the vertically expanding strip into a full-height inspector.

| Alternative considered | Assessment |
| --- | --- |
| Refine the inline accordion | Smallest change, but a long context list still competes with conversation height. |
| Full-height side inspector | Recommended: dedicated space for sources, actions, and reading while keeping the conversation available on wide screens. |
| A modal for every inspection | Roomy, but repeatedly obscures the conversation and interrupts movement between items. |

The proposed behaviour is:

1. A clearly labelled **Context Data · N items** control remains in the chat header, with an open indicator, strong hover/focus feedback, and a persistent open state. The count describes listed items, including removed items where currently represented; it does not imply that every item is in the model’s prompt.
2. On wide windows the inspector docks beside chat, typically using 440–520px. It docks only when the conversation retains a usable width. At smaller widths it becomes an overlay with an obvious close/back action.
3. Each item receives a readable title, separate metadata, a status badge, and a clear inspect action. Important titles wrap instead of disappearing into very short truncations.
4. Selecting an item opens its detail within the inspector. Back returns to the same list position. Original evidence and extracted facts receive labelled sections, generous reading space, and an expanded reading mode for long material.
5. Common actions stay visible in detail. Less frequent management actions move into a labelled menu or action section. Removing original evidence and deleting extracted facts remain distinct operations.
6. Every request shows local progress and handles failure. Preview, save, cancel, remove, and restore preserve their current backend meanings. Unsaved previews are protected from accidental dismissal or background refresh.
7. Counts and token information use values the backend actually supplies; approximate values remain labelled as estimates. The design will not equate retained records with evidence actually selected for a particular turn.
8. The context inspector and file editor share a coordinated side-panel area. They cannot squeeze the chat with two competing drawers. Switching preserves any open editor draft and provides a clear way back.

The existing technical terms can remain, supported by short explanations where necessary. This is primarily a change to space, discoverability, and feedback, not a redesign of evidence retention.

**5. Chat controls and the rest of the UI**

| Area | Planned changes |
| --- | --- |
| Composer | Clear input boundary, comfortable padding, obvious attachment control, stable Send/Stop placement, readable reasoning controls, and accessible attachment removal. |
| Send and Stop | One button style from the shared system. Distinct ready, unavailable, generating, cancellation-pending where supported, and error states. Stop remains operable during generation. Status wording reflects what the application can actually confirm. |
| Loading and progress | Shared indicator geometry and timing across chat, model switching, context operations, uploads, mail, and jobs. No animation continues merely because an idle spinner is transparent. |
| Chat content | Consistent message spacing, source cards, disclosures, copy actions, and readable text selection. Preserve the ability to scroll up while a response arrives. |
| Reply feedback | Thumbs up/down, selected reactions, the required downvote-reason chooser, saved-reason visibility, clear/change behaviour, saving feedback, and visible save failures. |
| Notices and warnings | Consistent presentation for model busy, offline services, context limits, failed operations, and confirmations. Preserve the distinctions between these situations and provide an appropriate next action. |
| Files and editor | Clear selected files/blocks, visible actions, room for filenames and content, consistent drawers, and stable selection while editing. |
| Memories | Readable entries, visible edit controls, clear edit/save/cancel states, and sufficient room for longer text. |
| Email | Better list/detail spacing, readable compose fields, consistent buttons and loading/error states. Preserve the boundaries around externally supplied email content. |
| Jobs | One results workspace; combined CV and preference setup; sources within setup; run activity with expandable logs; readable job details with explicit editing. See section 5d and the complete Jobs inventory. |
| Settings and onboarding | Consistent field labels, section spacing, control states, progress indicators, and reachable action footers. |

Selection, keyboard focus, hovering, and text highlighting will use distinct treatments so the user can tell them apart.

**5a. Reply feedback and conditional UI details**

**Thumbs up, thumbs down, and reasons.** Both buttons receive comfortable targets, accessible names, hover/focus feedback, and a persistent selected state. The selected meaning must be evident without relying only on green or red. The reason chooser opens as a clearly associated, labelled region with readable choices and adequate width; it must not force long reason labels into the same cramped row as the thumb buttons. Opening it near the viewport edge must not hide choices.

Keep the existing structured reason values, the requirement to choose a reason for a downvote, and the filtering of tool-related reasons to replies that used tools. Clicking an already selected thumb continues to clear that rating and reason. Cancelling the reason chooser, pressing Escape, or dismissing it leaves the previous saved rating intact. Show the saved reason in a compact, readable form that can be inspected again. A thumbs-up replacing a downvote clears the old reason as the backend already specifies.

Show that a rating is being saved; serialise submissions for that reply so repeated clicks cannot unintentionally toggle it twice or let an older response override a newer choice. On failure, retain the last confirmed state, show a local error, and leave recovery available. A connection failure can leave the write outcome uncertain: reconcile with the server before retrying the existing toggle operation, rather than blindly resending it. Cover both freshly streamed replies and replies loaded from history. The current frontend only logs rating-save failures to the console; visible failure handling is part of the implementation.

**AI busy with another task.** Use a readable, labelled availability notice close to the affected action. Give it a consistent status treatment distinct from a failed operation or offline service. Preserve the useful task description returned by the backend. Show progress, task navigation, or cancellation only when the existing application actually supplies and supports them. The message must not imply that a rejected request is queued or that generation has begun.

Handle both HTTP error responses, including the existing busy 409 response, and errors delivered after streaming begins. Use the available response information to distinguish busy, offline, context-full, and general failures; missing error information must not automatically become an AI-busy claim. Restore the correct Send/Stop and loading state and clear any obsolete editor overlay. Keep rejected text, attachments, and file references recoverable without overwriting a newer draft. Reading and navigating remain available where they do not require model access. A manual retry is offered only when its execution semantics are known; ambiguous disconnects must not automatically resend potentially executed tasks. Notices persist long enough to read, repeated errors do not stack duplicates, and dismissal does not falsely mark the model available.

**Other conditional details.** Apply the same treatment to context-limit warnings and condensation preview/confirmation; model switching, download and cancellation; attachment rejection, upload and sync results; editor locks and unsaved changes; copy success/failure; destructive-action confirmations; empty/filter/no-results states; and email/job operation feedback. Include the small source, thinking, data-fetching, and performance-detail disclosures. Audit existing native alerts, transient notices, and console-only failures that leave a user waiting, then route relevant feedback into the shared notice or dialog system. Completion requires inventory and verification across these states.

**5b. AI availability before, during, and after a task**

The current busy warning is too late to be the only indication of availability. Add a compact, persistent AI status in the shell, with the same information beside inference actions such as Send, Find Jobs, CV extraction, and AI-assisted editing. Service health and current model occupancy have different meanings and will be labelled accordingly.

| Actual condition | What the user sees and can do |
| --- | --- |
| Availability being checked or connection uncertain | “Checking AI availability…” or “AI status unavailable.” Do not present an unconfirmed ready state. Keep reading and draft editing usable; handle any attempted request normally and explain the outcome. |
| Ready | A quiet “AI ready” state. Enable actions whose other prerequisites are satisfied. |
| Generating in this conversation | The task status beside the response/composer, with the existing Stop action and truthful cancellation feedback. |
| Busy elsewhere | “AI busy” plus the current task description when supplied, such as a job search or another conversation. Explain that the draft is retained and that the request has not been queued. Offer “View task” only when its location is known. |
| Busy request rejected despite a ready indicator | A local notice explains the conflict, restores or retains the submitted draft and references safely, and updates availability. A race at submission must remain recoverable. |
| Task finished and availability confirmed | Update the status and re-enable eligible actions automatically. Leave the draft ready for the user to submit; do not submit it automatically. |
| Offline, context full, or another failure | Use a distinct explanation and the relevant recovery action. Do not translate every failure into “AI busy.” |

When the AI is known to be occupied, disable only actions that require it. Users can still browse chats, read jobs, inspect existing files, and edit drafts. Any affected control has an adjacent reason; the interface must not become a page-wide blocking overlay. Preserve the original draft if a newer draft has since been entered, rather than overwriting the newer text during error recovery.

Use current stream events and existing task status where available. Add a small read-only availability response if needed to make occupancy visible across workspaces. It must describe the current lock owner, not an old status record that outlived its lock. No invented queue, percentage, estimated finish time, or generic cancellation of an unidentified task. Cancellation remains available only for operations the application already supports cancelling.

One shared availability updater supplies all components. Avoid overlapping requests and one timer per widget; reduce activity when the page is hidden and refresh when the user returns. Status refreshes must be lightweight and must not run the full page bootstrap or repeated launcher model-list lookups. Slow or failed status checks produce an uncertain state, not a false “ready.”

**5c. Chat selection must respond to one deliberate click**

The source identifies two concrete areas to address. These are plausible contributors to the reported symptom; the intermittent two-click behaviour has not yet been reproduced in a browser.

- In `tab-chats.php`, the entire padded conversation row has hover styling and a pointer cursor, but only the inner title link navigates. In normal mode, clicking the surrounding padding does nothing. The star button is also a separate positioned target that can be transparent while still receiving clicks.
- Conversation links reload the full application. Before rendering, `index.php` requests the launcher's model list with a three-second timeout. That is a possible delay, not evidence that every click takes three seconds. Health checks and page-data loading are additional work to measure.

The proposed changes are:

1. Make the complete visible main area of each row a real navigation target. Give the star its own visible, non-overlapping target. Manage mode still selects rows; it must look unmistakably different from navigation mode.
2. Acknowledge the first click immediately. Show the destination as loading, distinguish it from the currently displayed conversation, and show a local recoverable error if loading fails. Do not make users click again to discover whether anything happened.
3. Load conversation history within the existing shell, using the existing rendering paths and a narrow read-only history response if needed. Preserve real link destinations, modifier-click/open-in-new-tab behaviour, browser Back/Forward, the selected filter, and list scroll position. Profile and remove unrelated launcher metadata work from ordinary chat switching.
4. Bind loading, title changes, message rendering, and errors to the requested session. Rapid A → B → C navigation must show C; a late response for A or B cannot replace it. Protect drafts and restore the appropriate conversation scroll position.
5. Keep an active generation associated with its original conversation while navigating. Indicate which chat is working and offer a return action. Reopening it must reconnect to its current UI state; switching chats must not silently cancel the task, move its output to another conversation, or let Stop target the wrong task. This changes navigation handling, not the model's concurrency rules.

Use the existing PHP/JavaScript stack. This is a targeted navigation improvement; no framework migration is required. Verify hit areas and network/render timing separately so a corrected click target does not conceal remaining latency.

**5d. Jobs: one workspace, with setup and activity in context**

Jobs currently distributes related work across category navigation, a second sidebar, and separate CVs, Profile, Registry, Run Logs, progress, and detail views. Replace this with a results workspace whose supporting controls stay associated with the search.

| Current UI | Proposed location and behaviour |
| --- | --- |
| Unread, Interested, Applied, Interview, Offer, History | Clear status filters with counts within the same Jobs workspace. Keep the list and selected job together; use list/detail navigation on narrow windows. |
| CV selector and Find Jobs | A compact search summary above results showing the chosen CV and setup readiness, with “Search setup” and “Find Jobs.” |
| Separate CVs and Profile views | One Search setup panel containing CV selection/upload/extraction and search preferences on the same scrollable page. No mandatory multi-step wizard. |
| Registry tab | A “Sources” section within Search setup, collapsed when configured. Keep URL templates, job-title/location inputs, and source management available with explanations. |
| Separate progress view and Run Logs tab | An inline run-activity strip showing actual progress, with expandable details and logs. The results remain available while work runs. |
| Job details displayed as a large editable form | A readable job summary, AI selection explanation, description, current stage, and relevant actions. “Edit details” reveals the editable fields with Save and Cancel. |
| Prune All inside Run Logs | A clearly labelled management action separated from routine run controls, with a confirmation naming the records it will remove. |

Within Search setup, distinguish the CV chosen for the next search from the saved default CV. Keep locations, work modes, employment types, minimum salary/currency, and free-text preferences together. The profile remains global as it is today; moving it beside the CV does not create a separate profile per CV. Show saved, unsaved, incomplete, extracting, busy, and failed states beside the relevant section. Explain which missing prerequisite prevents Find Jobs.

Source code currently reloads CVs, profile, registry, and the CV selector when Jobs opens. Profile loading writes directly into the form, and CV-selector refresh chooses the active/default CV again. Change this to deliberate loading and refresh: reopening Jobs must preserve an unfinished setup edit, the explicitly selected CV, the current filter, and the selected result. Stale detail responses must not replace the latest selected job. If a search uses a configuration snapshot, explain which edits apply to the next run only after confirming that behaviour from the backend.

Keep all existing job-state actions and conditional interview/offer fields. Present the relevant next action prominently, with less frequent actions grouped clearly. Rename “Apply” to “Record application” because the existing flow records the application date and CV; keep “Open listing” as the separate external action. Do not imply that the app has submitted an application. Preserve stored CV snapshots, history, blocking duration and effects, and the rules for compatible batch actions.

**6. Implementation approach and performance**

Continue with the existing PHP templates and modular JavaScript. Centralise reusable colours, dimensions, typography, and state styles in the shared CSS layer. Reuse shared component classes and small helpers where they prevent genuine duplication. Server-rendered rows and rows added by JavaScript must use the same visual rules.

The source already contains an incremental response renderer using animation frames. Preserve that work and test it with long responses, code, tables, citations, and panels opening during streaming.

Replace browser-time Tailwind compilation with a reproducible, compatible CSS build. Include PHP templates, JavaScript-created markup, and dynamically selected class variants in that build so production styling matches the preview. Account for development and Docker delivery; this does not require adopting a frontend framework.

Profile startup, idle activity, streaming, and panel transitions. Limit expensive blur and animated shadows; favour transform and opacity where appropriate. Animate disclosure height only for the transition, then allow natural content height. Clean up timers, observers, and animation frames when their work ends.

Existing business rules, persistence, model orchestration, and action semantics remain the foundation. The UI scope includes the minimum request/state handling needed for reliable navigation and feedback: narrow read-only availability/history responses if absent, and accurate error classification where current responses do not expose the distinction. It does not include a new task queue, changed concurrency policy, new job-search logic, or a data-model redesign.

**7. Implementation sequence**

| Stage | Reviewable result |
| --- | --- |
| Establish the baseline | Refresh from the current repository head, read applicable instructions, reconcile section 9 against that revision, establish a browser preview using the real templates, and capture representative screens and states. Reproduce chat hit-area failures and measure click-to-feedback, request time, and history rendering separately. |
| Establish shared components | Implement the palette, typography, spacing, controls, focus/selection rules, disclosures, and loading states in a small representative preview. |
| Modernise shell and chat | Implement shared AI availability, reliable chat selection and session isolation, layout, composer, streaming presentation, reply feedback, and the context inspector. |
| Reorganise Jobs | Deliver the unified results workspace, combined Search setup, readable job detail/edit modes, and contextual run activity. Preserve existing state transitions and application records. |
| Extend across remaining workspaces | Apply the same rules to files/editor, memories, email, settings, onboarding, and existing model-statistics/event-log screens. Inspect JavaScript-created content as well as initial HTML. |
| Verify and deliver | Resolve concrete layout/state regressions, capture comparison screenshots, document the checks performed, and open a draft PR on a dedicated branch. |

Implementation update, 15 September 2026: PHP 8.3.6 is available and the real templates and ES modules have been checked with synthetic data. Twenty DOM/state tests and 43 existing rating/job-state assertions pass. Browser preview access is blocked in this environment, so screenshots, visual acceptance, and performance measurements remain outstanding. MySQL, Redis, inference, and email integration also require the normal development stack. The implementation and exact validation limits are recorded in docs/ui-validation.md; fixture checks are not full integration tests.

**8. Acceptance checks**

- Inspect the complete UI at 1366×768 and 1920×1080, plus a wider desktop, a narrow window, and 200% browser zoom. Include a touch-sized viewport for interaction and overflow checks.
- Verify that long titles, filenames, messages, and context lists do not cover controls or make them unreachable.
- Check hover, focus, pressed, selected/open, disabled, busy, empty, success, and failure states for the shared controls.
- Exercise thumbs up/down, clearing a vote, choosing and cancelling a required reason, tool-specific reason filtering, saved-reason display, rapid input, save rejection, ambiguous network failure, and persistence after reload. Verify both history and newly streamed replies.
- Exercise model-busy responses before and during streaming, offline service responses, context limits, and generic failures. Verify accurate wording, readable notices, recoverable input, correct control/overlay cleanup, and recovery without unintended duplicate work.
- Verify that AI availability is visible before submission, follows the current task across workspaces, recovers when that task finishes, and becomes uncertain on stale/failed checks. A known busy model must leave reading and draft editing usable; no request is silently queued or automatically resubmitted.
- Open chats with a single click on title, icon, and row padding; use the star without navigating; test Manage mode separately. Exercise rapid A → B → C navigation, slow/failing responses, browser Back/Forward, modifier clicks, draft preservation, and navigation during streaming. Output and Stop must remain bound to the originating session.
- Verify that ordinary conversation switching avoids a full launcher/model-list bootstrap and acknowledges the click immediately, including under a slow connection. Record measured navigation and rendering time against the baseline; do not claim a speed-up from source inspection alone.
- Exercise the combined Jobs setup: upload/select/default/extract/delete CV, edit and save preferences, manage sources, and reopen the workspace with unsaved edits. A refresh must not reset the selected CV or overwrite a draft. Verify search readiness, busy rejection, progress, cancellation, completion, errors, and log access with results still available.
- Exercise every existing individual and compatible batch job-state transition, application date/CV recording, interview and offer fields, block confirmations, restore/delete, and Prune All. Verify filter counts, pagination, selection, readable detail/edit modes, and protection against stale detail loads or unsaved edit loss.
- Trace each item in the UI-state inventory to a rendered scenario and a verification result, including warning/confirmation dialogs and controls that are initially hidden. Explicitly identify any service-dependent scenario that could not be exercised.
- Open and close disclosures rapidly; switch context items while data loads; close a panel before a request finishes. Stale responses must not reopen panels or overwrite a newer selection.
- Test context inspection, atomization preview, commit/cancel, edit, remove, and restore, including the transition between context and an unsaved file editor.
- Test Send/Stop, failures and retries, attachments, streaming code/tables, copy actions, and scrolling away from the latest response.
- Confirm that initial PHP markup and dynamically added rows remain visually and behaviourally consistent after reload.
- Measure text/control contrast, exercise keyboard navigation and focus restoration, and verify reduced-motion behaviour.
- Compare performance against the baseline on the same preview and workload. Investigate visible jank, repeated reflow, lingering busy indicators, and unnecessary idle animation.
- Use targeted browser checks for these state and layout risks and syntax checks for changed PHP/JavaScript. Add regression tests for concrete failure cases, rather than assertions for every cosmetic detail.

The deliverable will be a draft PR, representative before/after screenshots, and a concise record of validation and any remaining limitations. Merging and deployment are separate from this plan.

**9. UI element inventory and intended changes**

This is the source-derived scope for implementation. Each row names the elements to change and their intended treatment; repeated instances share the same treatment. Hidden, streamed, and dynamically generated controls are included. Every row also inherits the typography, target-size, keyboard, loading, error, and reduced-motion rules above. Rendered verification is still required; this inventory is not a claim that every state has already been tested.

**Shell and shared elements** — sidebar.php, index.php, styles.css, ui.js, tabs.js.

| ID · Elements | Intended change |
| --- | --- |
| G01 · Application canvas, panels, borders, shadows, separators, typography, icons | Apply the shared navy/cyan visual system, readable sizes, consistent spacing, and restrained effects. |
| G02 · New Chat; Chats, Files, Brain/Memories, Mails/Email, Jobs navigation | Use clear labels and generous targets; show the current workspace; preserve state when switching. |
| G03 · Sidebar, collapse/expand control, pane headers and scroll areas | Add a responsive, collapsible navigation layout; prevent nested panes from squeezing their content. |
| G04 · Settings opener; System Health summary and disclosure | Separate settings from status; make database, Redis, model name, and online/offline details readable. |
| G05 · Global AI availability, current-task detail, return-to-task action | Add the shared ready/busy/checking/unavailable treatment described in section 5b. |
| G06 · Clear All History and confirmation | Keep the destructive action separate and explain its actual deletion scope before confirming. |
| G07 · Buttons, icon buttons, links, checkboxes, selectors, text fields, textareas | Standardise dimensions and all interaction states; pair labels with controls and expose validation locally. |
| G08 · Menus, disclosures, dialogs, drawers, close/back controls | Consistent open/close treatment, focus handling, Escape behaviour, and reachable controls. |
| G09 · Spinners, progress bars, notices, tooltips, empty states, confirmations | Shared indicators and plain status text; replace relevant alert/console-only failures with readable feedback. |
| G10 · Text highlighting, selected items, keyboard focus, scrollbars | Distinguish selection from focus and text highlighting; preserve readable contrast in each state. |

**Conversation navigation** — tab-chats.php and tabs/tabsChat*.js.

| ID · Elements | Intended change |
| --- | --- |
| N01 · Conversations heading; All and Starred filters | Readable hierarchy, visible active filter, and appropriate empty/filter-empty messages. |
| N02 · Conversation row, icon, title, padding, current/loading state | Make the entire main row a navigation target; acknowledge one click immediately and show loading/failure accurately. |
| N03 · Star/unstar button | A separate visible target, keyboard operation, confirmed state, and visible rollback/error handling. |
| N04 · Manage/Cancel mode; row selection indicators | Make selection mode obvious and independent of normal navigation. |
| N05 · Selected count, Delete selected, Cancel, deletion confirmation | Stable action bar, correct disabled state, exact deletion scope, and visible completion/failure. |
| N06 · List scroll, selected conversation, browser navigation | Preserve filter/scroll/drafts; handle Back/Forward and rapid navigation without stale content. |
| N07 · Working-conversation indicator and return link | Keep active generation associated with its originating conversation when another is opened. |

**Chat header and composer** — chat-window.php, app.js, chatManager.js, reasoningEffort.js, fileHandler.js.

| ID · Elements | Intended change |
| --- | --- |
| C01 · Conversation title; Operational/Offline badges | Give the title room; use clear service/availability labels with distinct meanings. |
| C02 · Token count, usage bar, Sync Limit and its progress/error | Make usage legible, indicate estimates where applicable, and prevent duplicate limit-sync requests. |
| C03 · Condense Chat; Context Data opener, count, open state | Clear labelled actions and persistent disclosure state; coordinate with their panels. |
| C04 · Empty-conversation prompt | A quiet, readable starting state without competing decorative emphasis. |
| C05 · Message textarea, placeholder, auto-grow, Enter/Shift+Enter | Clear input boundary, comfortable typing area, predictable shortcuts, retained drafts. |
| C06 · Attach button, file picker, drag/paste handling | Obvious upload affordance, rejection messages, and consistent progress. |
| C07 · Attachment previews, filenames/types, remove buttons | Readable names and distinct removal targets; preserve or recover references on rejection. |
| C08 · Attached-file chips; selected-editor-block summary and clear | Show exactly what accompanies the message and allow clear removal without losing the draft. |
| C09 · Conditional reasoning control: Low/Medium/High or Off/On | Consistent labels, targets, and selected state while preserving model-specific options. |
| C10 · Send, Stop, loading ring, cancellation feedback | Stable geometry, clear states, no idle animation, correct task association. |
| C11 · Busy/offline/context-full/send-failure notices and recovery | Explain the actual cause beside the composer, retain input, and prevent unintended duplicate submissions. |

**Messages and generated cards** — streamResponse.js, markdown.js, replyRating.js, chatClipboard.js, chatFile*.js, chatEmailCards.js, chatBriefingCards.js, chatTodoistActions.js.

| ID · Elements | Intended change |
| --- | --- |
| M01 · User/assistant labels, message surfaces, spacing | Consistent hierarchy and readable line length across history and live responses. |
| M02 · Paragraphs, headings, lists, quotes, links, tables, code, syntax, math | Coherent typography and overflow behaviour; preserve existing rendering and content boundaries. |
| M03 · Message copy, Copy Entire Message, code/metrics/path copy | Consistent placement and explicit copy success/failure without moving content. |
| M04 · Streaming cursor; Initializing, Searching, Extracting, Condensing, Thinking labels | One restrained status treatment using actual phases; distinguish stopped/incomplete/failed output. |
| M05 · Execution Trace header, current task, timer, step rows, completed states | Readable expandable activity, stable progress layout, clear active/completed distinction. |
| M06 · Thinking disclosure, preview/full text, chevron | Generous header target, predictable expand/collapse, readable long reasoning. |
| M07 · Sources heading/count, source cards, titles/domains, external links | Clear citation hierarchy and link targets with wrapping titles. |
| M08 · Performance summary, call chain, metric tables and disclosures | Readable secondary details; prevent dense metadata from competing with the answer. |
| M09 · Thumbs up/down, selected state, clear/change behaviour | Accessible controls and reliable per-reply saving as specified in section 5a. |
| M10 · Downvote reason chooser, cancellation, saved reason, save errors | Roomy labelled choices, existing required values/filtering, preserved confirmed state. |
| M11 · Inline image/document references, accordions, previews, file candidates | Consistent cards, local loading/failure, clear selected/open state. |
| M12 · File-card Open editor, Show in explorer, Append to chat, Copy path | Visible actions with distinct purposes; preserve current file/reference behaviour. |
| M13 · Inline email subject/sender/date/snippet and Open email | Readable previews, clear navigation, local loading/error, correct message selection. |
| M14 · Suggested tasks: count, title, due date, Accept & Create, conflict confirmation | Clear action hierarchy, creation progress/result, and existing conflict semantics. |
| M15 · Todoist delete action and confirmation; background consolidation notice | Consistent task feedback and cancellation/error wording using supported operations. |

**Context inspector** — chatContextData.js and chat-window.php.

| ID · Elements | Intended change |
| --- | --- |
| X01 · Inspector container, dock/overlay, header, back, close, expanded reading mode | Full-height reading space; coordinate with the editor and preserve list position. |
| X02 · Context list, count, empty state, item title/query, Inspect action | Readable rows with wrapping titles and obvious navigation. |
| X03 · Tool/source count, tokens, Raw/Raw+atoms/Atomized/Evicted badges | Accurate metadata; distinguish retained records from evidence actually used for a turn. |
| X04 · Source links, original evidence, backing chunks | Label content sections and provide enough room for long evidence. |
| X05 · Extracted facts/atoms, source IDs, approximate tokens, transformation indicator | Make facts and provenance inspectable without cramped nested boxes. |
| X06 · Atomize/re-atomize, preview editor/count, Done, Cancel | Clear preview-versus-commit states; explain that Done also evicts raw material where that is the existing action. |
| X07 · Edit facts, editor, Done/Cancel | Protect unsaved edits and show save outcome locally. |
| X08 · Delete atoms, evict original evidence, restore | Distinct labelled actions and confirmations where destructive; preserve backend meanings. |
| X09 · Item loading, failures/retry, count refresh, panel switching | Prevent stale responses, lost previews, reopening dismissed panels, or obsolete counts. |

**Condensation** — ui.js, app.js, chatManager.js.

| ID · Elements | Intended change |
| --- | --- |
| O01 · Context-limit warning, Not now, Yes/Optimize | Explain the condition and choices; keep the pending request recoverable. |
| O02 · Condensation spinner and current step | Shared status treatment with correct busy/failure recovery. |
| O03 · Proposed memories, selection checkboxes, Cancel, Apply selected | Readable review list, visible selection count, stable action footer. |
| O04 · Memory-write failure, capacity/full-context state | Show what failed and preserve the preview/request where recovery is possible. |

**File editor** — chat-file-editor-drawer.php and chatEditor*.js.

| ID · Elements | Intended change |
| --- | --- |
| E01 · Editor drawer, filename, close and Save | Roomy coordinated panel, explicit unsaved state and save feedback. |
| E02 · Blocks, line numbers, individual/range selection | Clear selection independent of text highlight and keyboard focus. |
| E03 · Edit selection, Delete selection, selection summary/clear | Stable, reachable contextual actions with explicit scope. |
| E04 · Per-line Edit/Delete | Visible on keyboard focus and usable without precise hovering. |
| E05 · Inline textareas, manual edits, fused ranges | Readable editing and predictable save/cancel behaviour while maintaining block semantics. |
| E06 · Generated partial/final block updates | Preserve selection and reading position; show task progress without flashing the whole editor. |
| E07 · Work-in-progress overlay/lock and status | Describe the actual task; clear obsolete locks on completion, rejection, or failure. |
| E08 · Unsaved-change, delete, open/save-failure dialogs/notices | Protect drafts, explain scope, and provide local recovery. |

**Files workspace** — gallery-workspace.php, tab-uploads.php, galleryBootstrap.js, fileHandler.js.

| ID · Elements | Intended change |
| --- | --- |
| F01 · Gallery heading and sidebar guidance | Clear workspace title; remove conflicting/redundant interaction instructions. |
| F02 · Search, All/Images/Documents filters, counts, Clear filters | Recognisable controls, visible active filter, keyboard-operable clearing. |
| F03 · Sync Disk, scan/index progress, completion/failure | Truthful phases and shared busy handling; keep browsing usable. |
| F04 · Upload dropzone, drag overlay, paste, batch progress | Consistent targets, file acceptance feedback, and recoverable failures. |
| F05 · Image/document cards, thumbnail/snippet, generated/original filenames | Readable cards; distinguish checkbox selection from opening the preview. |
| F06 · Selection highlight/count, batch bar, Append/Delete/clear selection | Obvious scope and stable actions; no accidental preview while selecting. |
| F07 · Previous/Next, page count | Consistent pagination with accurate disabled/loading states. |
| F08 · Preview drawer, title, close, file details, image/text body | Spacious reading, sensible long-content scrolling, local loading/error. |
| F09 · Show in explorer, Append to chat, single Delete | Clear labels and feedback; cross-workspace attachment preserves the chat draft. |
| F10 · Single/batch deletion confirmation and result | Name the affected files and extracted records accurately; prevent duplicate deletion. |

**Memories** — tab-memories.php, tabsMemoryEdit.js, ui.js.

| ID · Elements | Intended change |
| --- | --- |
| B01 · Memory count/capacity and empty/full state | Readable, accurate capacity information and next actions. |
| B02 · Consolidate & Clean and progress | Shared AI availability and truthful operation feedback. |
| B03 · Add custom memory input and Add | Clear input/action relationship, validation, and retained text on failure. |
| B04 · Select all, item checks, selected count, Delete selected | Consistent selection and exact-scope confirmation. |
| B05 · Memory text, Edit/Delete controls | Room for long entries and visible actions. |
| B06 · Edit textarea, Save/Cancel, deletion and write feedback | Stable local editing, protected drafts, readable outcomes. |

**Email** — tab-emails.php, email-workspace.php, tabsEmailAccount.js, email/*.js, chatUnifiedBriefing.js.

| ID · Elements | Intended change |
| --- | --- |
| A01 · Account list, selected account, status, Disconnect/confirmation | Clear account selection and connection state; separate removal target. |
| A02 · Add account dialog, title, close, provider, custom label | Consistent dialog and plain labels; preserve Gmail/Yandex/Yahoo/Custom IMAP choices. |
| A03 · Email/app-password, conditional host/port, Connect/Cancel, connection errors | Readable fields, correct credential-input treatment, local validation and connecting state. |
| A04 · Unified briefing trigger; include already-read mail from last 24 hours | Clear scope, visible option state, shared AI busy feedback. |
| A05 · Inbox account selector, sender, subject, date, snippet, read/unread indicator | Readable list with distinct current message and read state. |
| A06 · Inbox pagination, loading, no-account/no-results and failure | Stable list position and clear recovery, without stale responses changing accounts. |
| A07 · Reader subject/from/date, HTML body, quoted history, sent-reply history | Roomy reading and consistent disclosures; preserve existing email-content isolation. |
| A08 · Reply opener; To, Subject, Body | Plain action-accurate labels, comfortable composition, preserved draft. |
| A09 · Cancel/Send reply and progress/success/error | Clear transmission state, duplicate-send protection, retained input on known rejection. |
| A10 · AI reply assistance and thinking/busy/error states | Explain model availability near the action and protect manual edits from late responses. |
| A11 · Open-email links from chat and return/list navigation | Correct account/message selection and predictable navigation under slow requests. |

Decorative email wording such as “Decrypting transmission” will become plain descriptions of the actual action. The futuristic character comes from visual styling rather than obscuring what controls do.

**Jobs: results and details** — tab-jobs.php, job-workspace.php, jobInbox.js, jobDetails.js, jobActions.js, jobBatchSelect.js.

| ID · Elements | Intended change |
| --- | --- |
| J01 · Jobs heading, selected-CV/setup summary, Search setup, Find Jobs | One clear starting point; explain readiness and AI availability before a search. |
| J02 · Unread/Interested/Applied/Interview/Offer/History filters and counts | Keep job stages in the same results workspace with a visible active filter. |
| J03 · Result cards: checkbox, title, company, location, work mode, salary, posted date | Distinct selection/open targets, readable metadata, immediate selection feedback. |
| J04 · Results count, pagination, no jobs/no results, loading/failure | Accurate list state, stable scroll, and recoverable errors. |
| J05 · Selected job, list/detail back navigation, title/company/state/reason | Readable default detail view; prevent late requests from replacing the selected job. |
| J06 · Edit details, Save, Cancel | Explicit edit mode, dirty-state protection, local validation and save feedback. |
| J07 · Editable title, company, posted date, source domain, work mode, employment type | Consistent labelled controls within edit mode; preserve existing field semantics. |
| J08 · Salary, applicant count, location, city, country, job URL, Open listing | Readable summary and edit fields; distinguish external navigation from changing the record. |
| J09 · AI selection comment and Markdown job description | Clear reading hierarchy with room for long descriptions. |
| J10 · Interview timestamps; offer compensation, deadline, notes | Reveal the existing conditional fields clearly; retain values across stage changes. |
| J11 · State history and raw metadata | Readable history; place technical metadata behind a secondary disclosure. |
| J12 · Interested, Not interested, Block company, Block source | Relevant actions grouped clearly; blocking confirmation explains duration and effect on unread jobs. |
| J13 · Record application, application date/time, CV, Confirm/Cancel | Rename the misleading Apply label; record existing application data and preserve CV snapshots. |
| J14 · Move to interview/offer; Rejected by company | Clear stage transitions, pending state, and updated list/detail/counts. |
| J15 · Offer accepted/rejected; Restore; Delete job | Distinct outcomes, precise confirmations where appropriate, visible persistence/failure. |
| J16 · Batch selected count, Clear, compatible stage actions and Delete | Preserve existing action compatibility; mixed stages cannot expose unsupported transitions or bulk Apply. |
| J17 · Blocked-company/domain counts and readout | Make active restrictions inspectable; do not add unsupported unblock behaviour. |

**Jobs: combined Search setup** — cvManager.js, profileEditor.js, registryManager.js, jobViews.js, jobWorkspaceBootstrap.js.

| ID · Elements | Intended change |
| --- | --- |
| J18 · Search setup panel, close/back, section headings, saved/dirty/readiness state | Put CVs and preferences together; preserve unfinished edits when returning to results. |
| J19 · CV picker/dropzone, accepted formats, filename/size chip, Remove | Clear upload area and local rejection feedback for PDF/DOCX/TXT/MD. |
| J20 · CV designation, Upload, disabled/uploading/error state | Readable fields, duplicate-upload prevention, clear completion. |
| J21 · CV list/designation, current selection, default/active badge, Set active | Distinguish this search's selection from the saved default; refreshing must not reset it. |
| J22 · Extract details, extraction progress/error, extracted Markdown preview, Delete CV | Keep extraction beside the selected CV; explain deletion scope while preserving application snapshots. |
| J23 · Locations; remote/hybrid/on-site; full-time/part-time | Combine global search preferences in one readable section with clear checkbox targets. |
| J24 · Minimum salary/currency, free-text preferences, completeness badge, Save/Discard | Show missing prerequisites and unsaved changes; no background refresh overwrites input. |
| J25 · Sources disclosure/list, domain and URL template | Fold Registry into setup with readable configured-source summaries. |
| J26 · URL template, job-title/location inputs and placeholder help | Explain source-specific parameters beside fields without exposing unrelated implementation detail. |
| J27 · Add/Edit/Update/Cancel edit, Delete source/confirmation, empty/error states | Complete the source editing flow with explicit cancellation and local outcomes. |

**Jobs: activity and management** — jobProgress.js, jobActions.js.

| ID · Elements | Intended change |
| --- | --- |
| J28 · Run activity strip, current phase, scraped/selected/source totals and failures | Keep actual progress visible beside results; avoid invented percentage or remaining-time estimates. |
| J29 · Cancel run, cancellation requested/confirmed/error, completion summary, dismiss | Truthful cancellation feedback; results remain usable during and after the run. |
| J30 · View logs, run status/start/counters, log severity/message/timestamp, empty/failure | Expand logs within run activity; make errors readable without a separate top-level tab. |
| J31 · Prune All and confirmation | Move to a clearly separated management section and name the exact deletion scope. |

**Settings and first-run setup** — modal-settings.php, onboarding.php, app.js.

| ID · Elements | Intended change |
| --- | --- |
| S01 · Settings dialog, title, close, scroll area and footer | Consistent spacious dialog with reachable Cancel/Save controls. |
| S02 · Grouped model selector, current model, context-size field and bounds | Clear labels and available choices; preserve current resolution rules. |
| S03 · Every generated configuration field, label, help and validation | Apply shared field treatment to all dynamically generated rows. Runtime-specific keys are not enumerated by reading private configuration. |
| S04 · Save Configuration, Cancel; resolve/download/start status, progress/percent, Cancel download, error | Clear phases, real progress values, correct cancellation and draft handling. |
| S05 · Onboarding model cards, names/specifications/descriptions, select/start | Readable choices and a clear selected/starting state. |
| S06 · Launcher unavailable, Retry, onboarding progress/percentage/cancel/error | Explain the actual condition and keep recovery visible. |

**Existing statistics and diagnostic screens** — models.php and logs.php.

| ID · Elements | Intended change |
| --- | --- |
| D01 · Model table: turns, decode/prefill/cache, faults/errors, positive percentage | Readable aligned metrics and consistent table/empty states. |
| D02 · Thumbs counts/reason breakdown, Event log link, Reset stats/confirmation | Clear feedback statistics and reset scope, with shared controls. |
| D03 · Event-log session input/Go, search/Search, severity/All filters | Readable filtering and explicit active state. |
| D04 · Type/source chips and removal, Reset/Clear all confirmation | Clear filter clearing versus destructive log deletion. |
| D05 · Session information, domain rankings/filter links, type/source summaries | Consistent secondary information with obvious drill-down actions. |
| D06 · Event rows, expanded details/arrays, call-metric tables, empty/error states | Readable dense data and predictable disclosure behaviour. |

The progress.php endpoint is a transport endpoint, not an additional screen. Its user-visible events are covered by the chat and activity rows above.

**Scope and delivery**

This plan covers the app-wide visual system, proactive AI availability, reliable chat navigation, the Context Data inspector, combined Jobs setup/activity, and all identified element groups above. Your instruction on 14 September 2026 authorises completing the plan and proceeding to implementation. Deliver changes on a dedicated branch as a draft PR with the actual validation results and any service-dependent limitations recorded.

Source examples informing the proposal: [global styles](https://github.com/lolmeherti/ai-workspace/blob/master/src/css/styles.css), [chat template](https://github.com/lolmeherti/ai-workspace/blob/master/src/views/chat-window.php), [context interaction code](https://github.com/lolmeherti/ai-workspace/blob/master/src/js/chat/chatContextData.js), and [stream renderer](https://github.com/lolmeherti/ai-workspace/blob/master/src/js/streamer/streamResponse.js).

Additional interaction references: [reply feedback](https://github.com/lolmeherti/ai-workspace/blob/master/src/js/chat/replyRating.js), [rating and reason rules](https://github.com/lolmeherti/ai-workspace/blob/master/src/App/Actions/RateReplyAction.php), and [model-busy messages](https://github.com/lolmeherti/ai-workspace/blob/master/src/App/Services/ModelLock.php).
