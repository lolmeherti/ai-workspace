<div class="flex-1 flex h-full overflow-hidden text-xs bg-[#040810]">
    <div class="w-1/3 border-r border-slate-850 flex flex-col h-full bg-[#080d16]/80">
        <div class="p-5 border-b border-slate-850 bg-[#0d1321]/60 select-none shrink-0">
            <h1 class="text-sm font-bold text-slate-100 flex items-center gap-2 tracking-wide uppercase">
                <uk-icon icon="briefcase" class="w-4 h-4 text-cyan-400"></uk-icon>
                Job Tracker
            </h1>
            <p class="text-[10px] text-slate-400 mt-1">Select a CV and run your saved sources.</p>

            <div class="mt-4 space-y-2.5">
                <select id="job-cv-select" class="w-full bg-[#0b1120] border border-cyan-500/10 hover:border-cyan-500/30 rounded-lg px-3 py-2 text-slate-200 outline-none transition-all text-xs font-mono">
                    <option value="">[ No CV selected ]</option>
                </select>
                <button id="job-find-btn" onclick="window.jobFindJobs()" class="w-full py-2 rounded-lg font-bold tracking-wider uppercase text-[10px] bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none flex items-center justify-center gap-2">
                    <uk-icon icon="search" class="w-3.5 h-3.5"></uk-icon> Find Jobs
                </button>
            </div>
        </div>

        <div id="job-list-view" class="flex-1 flex flex-col min-h-0 border-t border-slate-850">
            <div class="flex items-center justify-between px-4 pt-3 pb-2 shrink-0 border-b border-slate-850">
                <span id="job-category-title" class="text-[10px] font-bold uppercase tracking-widest text-cyan-400 truncate">Jobs</span>
                <span id="job-list-count" class="text-[9px] font-bold text-slate-500 shrink-0"></span>
            </div>
            <div id="job-cards" class="flex-1 overflow-y-auto px-3 py-2 space-y-1.5">
                <div class="text-center py-10 text-slate-600 text-[9px] uppercase tracking-widest font-bold select-none">Select a category to view jobs</div>
            </div>
            <div id="job-pagination" class="shrink-0 px-3 py-1 border-t border-slate-850"></div>
        </div>

        <div id="job-blocks-readout" class="shrink-0 px-4 py-1.5 border-t border-slate-850 text-[9px] hidden"></div>
        <div id="job-batch-bar" class="shrink-0 hidden"></div>

        <div class="border-t border-slate-850 flex bg-[#090d18] shrink-0">
            <button class="job-view-btn flex-1 py-2.5 text-[9px] font-bold uppercase tracking-widest text-slate-400 hover:text-cyan-400 transition-colors" data-view="cvs" onclick="window.switchJobView('cvs')">CVs</button>
            <button class="job-view-btn flex-1 py-2.5 text-[9px] font-bold uppercase tracking-widest text-slate-400 hover:text-cyan-400 transition-colors" data-view="profile" onclick="window.switchJobView('profile')">Profile</button>
            <button class="job-view-btn flex-1 py-2.5 text-[9px] font-bold uppercase tracking-widest text-slate-400 hover:text-cyan-400 transition-colors" data-view="registry" onclick="window.switchJobView('registry')">Registry</button>
            <button class="job-view-btn flex-1 py-2.5 text-[9px] font-bold uppercase tracking-widest text-slate-400 hover:text-cyan-400 transition-colors" data-view="logs" onclick="window.openRunLogs()">Run Logs</button>
        </div>
    </div>

    <div class="flex-1 flex flex-col h-full bg-[#040810] relative overflow-hidden">
        <div id="job-view-details" class="job-view h-full overflow-y-auto">
            <div id="job-details-container" class="p-6 h-full"></div>
        </div>

        <div id="job-view-progress" class="job-view hidden h-full overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-sm font-bold text-slate-100 uppercase tracking-wide flex items-center gap-2">
                        <uk-icon icon="search" class="w-4 h-4 text-cyan-400"></uk-icon> Finding Jobs
                    </h2>
                    <button id="job-run-cancel" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-rose-900/40 text-rose-400 border border-rose-500/30 hover:border-rose-400/50 transition-all cursor-pointer outline-none">Cancel</button>
                </div>
                <div id="job-progress-body"></div>
            </div>
        </div>

        <div id="job-view-cvs" class="job-view hidden h-full overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-sm font-bold text-slate-100 uppercase tracking-wide flex items-center gap-2">
                        <uk-icon icon="file-text" class="w-4 h-4 text-cyan-400"></uk-icon> CV Management
                    </h2>
                </div>

                <form id="cv-upload-form" class="mb-6 p-5 rounded-2xl border border-slate-800 bg-[#0a0f1d]/70">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-slate-100">
                            <uk-icon icon="cloud-upload" class="w-4 h-4 text-cyan-400"></uk-icon> Upload a CV
                        </h3>
                        <span class="text-[8px] font-bold uppercase tracking-widest text-slate-500">PDF &middot; DOCX &middot; TXT &middot; MD</span>
                    </div>
                    <p class="text-[9px] text-slate-500 mb-4 leading-relaxed">Add a resume to your library to run discovery against it. Uploading does not run AI extraction &mdash; use Extract Details afterwards.</p>

                    <input type="file" id="cv-file-input" name="cv" accept=".pdf,.docx,.txt,.md" class="sr-only">

                    <label id="cv-dropzone" for="cv-file-input" class="group flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-slate-700 hover:border-cyan-500/50 hover:bg-cyan-500/[0.04] cursor-pointer transition-all px-6 py-9 text-center select-none">
                        <uk-icon icon="cloud-upload" class="w-9 h-9 text-slate-600 group-hover:text-cyan-400 transition-colors"></uk-icon>
                        <div>
                            <div class="text-[11px] font-bold text-slate-300 group-hover:text-cyan-300 transition-colors">Drag &amp; drop your CV here</div>
                            <div class="text-[9px] text-slate-500 mt-1">or <span class="text-cyan-400 font-bold underline underline-offset-2 decoration-cyan-500/50">browse your files</span></div>
                        </div>
                    </label>

                    <div id="cv-file-chip" class="hidden mt-3 flex items-center gap-3 p-3 rounded-lg border border-cyan-500/25 bg-cyan-500/[0.06]">
                        <uk-icon icon="file-text" class="w-4 h-4 text-cyan-400 shrink-0"></uk-icon>
                        <div class="min-w-0 flex-1">
                            <div id="cv-file-name" class="text-[10px] font-bold text-slate-100 truncate"></div>
                            <div id="cv-file-size" class="text-[8px] text-slate-500 font-mono mt-0.5"></div>
                        </div>
                        <button type="button" id="cv-file-remove" title="Remove file" class="shrink-0 w-6 h-6 flex items-center justify-center rounded-md text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 border border-transparent hover:border-rose-500/40 transition-all cursor-pointer outline-none"><uk-icon icon="close" class="w-3.5 h-3.5"></uk-icon></button>
                    </div>

                    <div class="mt-4">
                        <label for="cv-designation" class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Designation <span class="text-slate-600 font-normal normal-case tracking-normal">(optional &mdash; defaults to the filename)</span></label>
                        <input type="text" id="cv-designation" placeholder="e.g. Senior Backend Engineer" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 outline-none focus:border-cyan-500/40 transition-colors placeholder:text-slate-600">
                    </div>

                    <div id="cv-upload-error" class="hidden mt-3 flex items-center gap-2 text-[10px] font-bold text-rose-400">
                        <uk-icon icon="warning" class="w-3.5 h-3.5 shrink-0"></uk-icon>
                        <span id="cv-upload-error-text"></span>
                    </div>

                    <div class="mt-5 flex items-center justify-end">
                        <button type="submit" id="cv-upload-submit" disabled class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none disabled:opacity-40 disabled:pointer-events-none">
                            <uk-icon icon="upload" class="w-3.5 h-3.5"></uk-icon>
                            <span id="cv-upload-label">Upload CV</span>
                        </button>
                    </div>
                </form>

                <div id="cv-list-container" class="space-y-3"></div>
            </div>
        </div>

        <div id="job-view-profile" class="job-view hidden h-full overflow-y-auto">
            <div class="p-6">
                <h2 class="text-sm font-bold text-slate-100 uppercase tracking-wide flex items-center gap-2 mb-5">
                    <uk-icon icon="user" class="w-4 h-4 text-cyan-400"></uk-icon> Global Job Profile
                </h2>

                <form id="profile-form" class="space-y-5 max-w-xl">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Preferred locations (comma separated)</label>
                        <input type="text" id="profile-locations" placeholder="Vienna, remote" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Work mode</label>
                        <div id="profile-work-mode" class="flex gap-2">
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="remote" class="accent-cyan-500"> Remote</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="hybrid" class="accent-cyan-500"> Hybrid</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="on_site" class="accent-cyan-500"> On-site</label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Employment type</label>
                        <div id="profile-employment" class="flex gap-2">
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="full-time" class="accent-cyan-500"> Full-time</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" value="part-time" class="accent-cyan-500"> Part-time</label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Salary min</label>
                            <input type="text" id="profile-salary-min" placeholder="70000" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Currency</label>
                            <input type="text" id="profile-salary-currency" placeholder="EUR" maxlength="3" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Free-text preferences</label>
                        <textarea id="profile-free-text" rows="4" placeholder="Anything else you prefer..." class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors"></textarea>
                    </div>

                    <div class="flex items-center justify-between gap-4 pt-2">
                        <span id="profile-complete-badge" class="text-[9px] font-bold uppercase tracking-widest text-slate-500"></span>
                        <button type="submit" class="px-5 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none">Save Profile</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="job-view-registry" class="job-view hidden h-full overflow-y-auto">
            <div class="p-6">
                <h2 class="text-sm font-bold text-slate-100 uppercase tracking-wide flex items-center gap-2 mb-5">
                    <uk-icon icon="database" class="w-4 h-4 text-cyan-400"></uk-icon> Sources
                </h2>

                <form id="registry-form" class="mb-6 p-4 border border-slate-850 rounded-xl bg-[#0a0f1d]/60 space-y-3">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Add / Edit Source</label>
                    <input type="text" id="reg-url" placeholder="https://www.karriere.at/jobs?keywords={job_title}&locations={location}" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors font-mono text-[11px]">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-1">Job title values</label>
                            <input type="text" id="reg-job-title" placeholder="php, go, cloud engineer" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-1">Location values</label>
                            <input type="text" id="reg-location" placeholder="wien und umgebung, remote" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                    </div>
                    <div class="flex gap-3 items-center">
                        <button type="submit" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 transition-all cursor-pointer outline-none"><span id="reg-save-label">Add</span></button>
                    </div>
                    <p class="text-[9px] text-slate-500 leading-relaxed">Use {job_title} and {location} in the URL where you want values substituted. Separate values with commas.</p>
                </form>

                <div id="registry-list-container" class="space-y-3"></div>
            </div>
        </div>

        <div id="job-view-logs" class="job-view hidden h-full overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-sm font-bold text-slate-100 uppercase tracking-wide flex items-center gap-2">
                        <uk-icon icon="activity" class="w-4 h-4 text-cyan-400"></uk-icon> Run Logs
                    </h2>
                    <button id="job-prune-btn" onclick="window.pruneJobs()" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-transparent hover:bg-rose-900/40 text-rose-400 border border-rose-500/30 hover:border-rose-400/50 transition-all cursor-pointer outline-none">Prune All</button>
                </div>
                <div id="job-logs-container">
                    <div class="text-center py-20 text-slate-600 flex flex-col items-center justify-center gap-3 select-none">
                        <uk-icon icon="activity" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                        <p class="text-[10px] tracking-widest uppercase font-bold">No job runs yet</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
