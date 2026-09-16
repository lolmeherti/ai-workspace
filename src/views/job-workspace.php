<div class="jobs-layout" data-feedback-region>
    <div id="job-notices" aria-live="polite"></div>
    <div class="jobs-results">
        <section class="jobs-list-pane" aria-label="Saved jobs">
            <header class="jobs-header">
                <div class="jobs-heading"><h1>Jobs</h1><p>Discover opportunities and track your applications.</p></div>
                <div class="jobs-cv-field"><label for="job-cv-select">CV for this search</label><select id="job-cv-select"><option value="">Select a CV</option></select></div>
                <div class="jobs-primary-actions">
                    <button type="button" class="ui-button ui-button--primary" id="job-find-btn" data-ai-action onclick="window.jobFindJobs()"><uk-icon icon="search" aria-hidden="true"></uk-icon> Find jobs</button>
                    <button type="button" class="ui-button" id="job-setup-open">Search setup</button>
                </div>
                <div class="job-stage-picker"><label for="job-stage-select">Application stage</label><select id="job-stage-select"><option value="unread">Unread</option></select></div>
            </header>
            <div id="job-list-view">
                <div class="jobs-list-heading"><h2 id="job-category-title">Unread</h2><span id="job-list-count" class="ui-muted"></span></div>
                <div id="job-cards" aria-label="Job list"><p class="jobs-empty">Loading saved jobs…</p></div>
                <div id="job-pagination"></div>
            </div>
            <div id="job-batch-bar" class="hidden"></div>
            <details class="job-management"><summary>Manage saved jobs and blocks</summary>
                <div id="job-blocks-readout" class="hidden"></div>
                <p class="ui-muted">Clear all saved jobs, discovery results, and run logs. Your CVs, preferences, sources, and blocks stay saved.</p>
                <button id="job-prune-btn" type="button" class="ui-button ui-button--danger" onclick="window.pruneJobs()">Clear all saved jobs</button>
            </details>
        </section>
        <section class="jobs-detail" aria-label="Job details and search activity">
            <details id="job-run-activity" class="job-run-activity">
                <summary>Search activity <span id="job-run-status" class="ui-muted">and run history</span></summary>
                <button type="button" class="ui-button jobs-back" id="job-activity-back">← Back to jobs</button>
                <div id="job-run-summary"></div>
                <div id="job-view-progress" class="job-view hidden h-full overflow-y-auto">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-sm font-bold text-slate-100 normal-case tracking-normal flex items-center gap-2"><uk-icon icon="search" class="w-4 h-4 text-cyan-400"></uk-icon> Finding Jobs</h2>
                            <button id="job-run-cancel" class="ui-button ui-button--danger">Cancel</button>
                        </div>
                        <div id="job-progress-body"></div>
                    </div>
                </div>
                <button type="button" class="ui-button" onclick="window.openRunLogs()">Load run history</button>
                <div id="job-view-logs" class="job-view hidden h-full overflow-y-auto">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-5"><h2 class="text-sm font-bold text-slate-100 normal-case tracking-normal flex items-center gap-2"><uk-icon icon="activity" class="w-4 h-4 text-cyan-400"></uk-icon> Run Logs</h2></div>
                        <div id="job-logs-container"><div class="jobs-empty">No job runs yet</div></div>
                    </div>
                </div>
            </details>
            <div id="job-view-details" class="job-view">
                <button type="button" class="ui-button jobs-back" id="job-details-back">← Back to jobs</button>
                <div id="job-details-container"></div>
            </div>
        </section>
    </div>
    <dialog id="job-setup" class="ui-dialog jobs-setup-dialog" aria-labelledby="job-setup-title" data-feedback-region>
        <header class="inspector-header"><div><h2 id="job-setup-title">Search setup</h2><p class="ui-muted">Your CV, preferences, and search sources together.</p></div><button type="button" id="job-setup-close" class="ui-button">Done</button></header>
        <div id="job-setup-notices"></div>
        <div class="jobs-setup-grid"><div id="job-view-cvs" class="job-setup-section">
            <div class="p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-sm font-bold text-slate-100 normal-case tracking-normal flex items-center gap-2">
                        <uk-icon icon="file-text" class="w-4 h-4 text-cyan-400"></uk-icon> CV Management
                    </h2>
                </div>

                <form id="cv-upload-form" class="mb-6 p-5 rounded-2xl border border-slate-800 bg-[#0a0f1d]/70">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="flex items-center gap-2 text-xs font-bold normal-case tracking-normal text-slate-100">
                            <uk-icon icon="cloud-upload" class="w-4 h-4 text-cyan-400"></uk-icon> Upload a CV
                        </h3>
                        <span class="text-xs font-bold normal-case tracking-normal text-slate-500">PDF &middot; DOCX &middot; TXT &middot; MD</span>
                    </div>
                    <p class="text-xs text-slate-500 mb-4 leading-relaxed">Add a resume to your library to run discovery against it. Uploading does not run AI extraction &mdash; use Extract Details afterwards.</p>

                    <input type="file" id="cv-file-input" name="cv" accept=".pdf,.docx,.txt,.md" class="sr-only">

                    <label id="cv-dropzone" for="cv-file-input" class="group flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-slate-700 hover:border-cyan-500/50 hover:bg-cyan-500/[0.04] cursor-pointer transition-all px-6 py-9 text-center select-none">
                        <uk-icon icon="cloud-upload" class="w-9 h-9 text-slate-600 group-hover:text-cyan-400 transition-colors"></uk-icon>
                        <div>
                            <div class="text-xs font-bold text-slate-300 group-hover:text-cyan-300 transition-colors">Drag &amp; drop your CV here</div>
                            <div class="text-xs text-slate-500 mt-1">or <span class="text-cyan-400 font-bold underline underline-offset-2 decoration-cyan-500/50">browse your files</span></div>
                        </div>
                    </label>

                    <div id="cv-file-chip" class="hidden mt-3 flex items-center gap-3 p-3 rounded-lg border border-cyan-500/25 bg-cyan-500/[0.06]">
                        <uk-icon icon="file-text" class="w-4 h-4 text-cyan-400 shrink-0"></uk-icon>
                        <div class="min-w-0 flex-1">
                            <div id="cv-file-name" class="text-xs font-bold text-slate-100 truncate"></div>
                            <div id="cv-file-size" class="text-xs text-slate-500 font-mono mt-0.5"></div>
                        </div>
                        <button type="button" id="cv-file-remove" title="Remove file" class="shrink-0 w-6 h-6 flex items-center justify-center rounded-md text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 border border-transparent hover:border-rose-500/40 transition-all cursor-pointer outline-none"><uk-icon icon="close" class="w-3.5 h-3.5"></uk-icon></button>
                    </div>

                    <div class="mt-4">
                        <label for="cv-designation" class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Designation <span class="text-slate-600 font-normal normal-case tracking-normal">(optional &mdash; defaults to the filename)</span></label>
                        <input type="text" id="cv-designation" placeholder="e.g. Senior Backend Engineer" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors placeholder:text-slate-600">
                    </div>

                    <div id="cv-upload-error" class="hidden mt-3 flex items-center gap-2 text-xs font-bold text-rose-400">
                        <uk-icon icon="warning" class="w-3.5 h-3.5 shrink-0"></uk-icon>
                        <span id="cv-upload-error-text"></span>
                    </div>

                    <div class="mt-5 flex items-center justify-end">
                        <button type="submit" id="cv-upload-submit" disabled class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-xs font-bold normal-case tracking-normal bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none disabled:opacity-40 disabled:pointer-events-none">
                            <uk-icon icon="upload" class="w-3.5 h-3.5"></uk-icon>
                            <span id="cv-upload-label">Upload CV</span>
                        </button>
                    </div>
                </form>

                <div id="cv-list-container" class="space-y-3"></div>
            </div>
        </div><div id="job-view-profile" class="job-setup-section">
            <div class="p-6">
                <h2 class="text-sm font-bold text-slate-100 normal-case tracking-normal flex items-center gap-2 mb-5">
                    <uk-icon icon="user" class="w-4 h-4 text-cyan-400"></uk-icon> Search preferences
                </h2>

                <p class="ui-muted mb-4">These preferences apply to every CV. Save changes before starting a search.</p><form id="profile-form" class="space-y-5 max-w-xl">
                    <div>
                        <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Preferred locations (comma separated)</label>
                        <input type="text" id="profile-locations" placeholder="Vienna, remote" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                    </div>

                    <div>
                        <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-2">Work mode</label>
                        <div id="profile-work-mode" class="flex gap-2">
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="remote" class="accent-cyan-500"> Remote</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="hybrid" class="accent-cyan-500"> Hybrid</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="on_site" class="accent-cyan-500"> On-site</label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-2">Employment type</label>
                        <div id="profile-employment" class="flex gap-2">
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="full-time" class="accent-cyan-500"> Full-time</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="part-time" class="accent-cyan-500"> Part-time</label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Salary min</label>
                            <input type="text" id="profile-salary-min" placeholder="70000" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Currency</label>
                            <input type="text" id="profile-salary-currency" placeholder="EUR" maxlength="3" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold normal-case tracking-normal text-slate-400 mb-1.5">Free-text preferences</label>
                        <textarea id="profile-free-text" rows="4" placeholder="Anything else you prefer..." class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors"></textarea>
                    </div>

                    <div class="flex items-center justify-between gap-4 pt-2">
                        <span id="profile-complete-badge" class="text-xs font-bold normal-case tracking-normal text-slate-500"></span>
                        <button type="button" id="profile-discard" class="ui-button">Discard changes</button><button type="submit" class="px-5 py-2 rounded-lg text-xs font-bold normal-case tracking-normal bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none">Save Profile</button>
                    </div>
                </form>
            </div>
        </div></div>
        <details id="job-source-settings"><summary>Search sources</summary><div id="job-view-registry" class="job-setup-section">
            <div class="p-6">
                <h2 class="text-sm font-bold text-slate-100 normal-case tracking-normal flex items-center gap-2 mb-5">
                    <uk-icon icon="database" class="w-4 h-4 text-cyan-400"></uk-icon> Sources
                </h2>

                <form id="registry-form" class="mb-6 p-4 border border-slate-850 rounded-xl bg-[#0a0f1d]/60 space-y-3">
                    <label class="block text-xs font-bold normal-case tracking-normal text-slate-400">Add / Edit Source</label>
                    <input type="text" id="reg-url" placeholder="https://www.karriere.at/jobs?keywords={job_title}&locations={location}" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors font-mono text-xs">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold normal-case tracking-normal text-slate-500 mb-1">Job title values</label>
                            <input type="text" id="reg-job-title" placeholder="php, go, cloud engineer" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold normal-case tracking-normal text-slate-500 mb-1">Location values</label>
                            <input type="text" id="reg-location" placeholder="wien und umgebung, remote" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                    </div>
                    <div class="flex gap-3 items-center">
                        <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold normal-case tracking-normal bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none"><span id="reg-save-label">Add source</span></button><button type="button" id="reg-cancel" class="ui-button">Cancel edit</button>
                    </div>
                    <p class="text-xs text-slate-500 leading-relaxed">Use {job_title} and {location} in the URL where you want values substituted. Separate values with commas.</p>
                </form>

                <div id="registry-list-container" class="space-y-3"></div>
            </div>
        </div></details>
    </dialog>
</div>
