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
            <header class="jobs-detail-tabs" role="tablist" aria-label="Right pane views">
                <button type="button" id="job-tab-details" class="job-tab is-active" role="tab" aria-selected="true" aria-controls="job-view-details">
                    <uk-icon icon="file-text"></uk-icon><span>Job Details</span>
                </button>
                <button type="button" id="job-tab-logs" class="job-tab" role="tab" aria-selected="false" aria-controls="job-view-activity">
                    <uk-icon icon="terminal"></uk-icon><span>Run Details</span>
                </button>
            </header>
            <div id="job-view-details" class="job-panel" role="tabpanel" aria-labelledby="job-tab-details">
                <button type="button" class="ui-button jobs-back" id="job-details-back">← Back to jobs</button>
                <div id="job-details-container"></div>
            </div>
            <div id="job-view-activity" class="job-panel hidden" role="tabpanel" aria-labelledby="job-tab-logs">
                <button type="button" class="ui-button jobs-back" id="job-activity-back">← Back to jobs</button>
                <div class="run-console-head">
                    <div class="run-console-title">
                        <span class="run-console-icon"><uk-icon icon="terminal"></uk-icon></span>
                        <div class="run-console-title-text">
                            <div class="run-console-title-row">
                                <h2>Search Activity Console</h2>
                                <span class="run-status-badge"><span class="run-status-dot"></span><span id="job-run-status">No active search</span></span>
                            </div>
                            <p>Execution logs and scraper diagnostics.</p>
                        </div>
                    </div>
                    <button type="button" class="job-btn job-btn--ghost" onclick="window.openRunLogs()"><uk-icon icon="history"></uk-icon> Load run history</button>
                </div>
                <div id="job-run-summary"></div>
                <div id="job-run-meta"></div>
                <div id="job-view-progress" class="job-run-panel hidden">
                    <div class="job-run-panel-inner">
                        <div class="job-run-panel-head">
                            <h2><uk-icon icon="search"></uk-icon> Finding Jobs</h2>
                            <button id="job-run-cancel" class="ui-button ui-button--danger">Cancel</button>
                        </div>
                        <div id="job-progress-body"></div>
                    </div>
                </div>
                <div id="job-view-logs" class="job-run-panel hidden">
                    <div id="job-logs-container"><div class="jobs-empty">No job runs yet</div></div>
                </div>
            </div>
        </section>
    </div>
    <dialog id="job-setup" class="ui-dialog jobs-setup-modal" aria-labelledby="job-setup-title" data-feedback-region>
        <header class="jobs-setup-header">
            <div class="jobs-setup-heading">
                <span class="jobs-setup-heading-icon"><uk-icon icon="settings"></uk-icon></span>
                <div>
                    <h2 id="job-setup-title">Search setup</h2>
                    <p>Your CV, preferences, and search sources together.</p>
                </div>
            </div>
            <button type="button" id="job-setup-close" class="job-btn job-btn--ghost">Done</button>
        </header>

        <div id="job-setup-notices"></div>

        <div class="jobs-setup-body">
            <div class="jobs-setup-grid">
                <section id="job-view-cvs" class="job-setup-card" aria-labelledby="job-cv-heading">
                    <div class="job-setup-card-head">
                        <h2 id="job-cv-heading" class="job-setup-card-title"><uk-icon icon="file-text"></uk-icon> CV &amp; Resume</h2>
                        <span class="job-setup-pill">Your library</span>
                    </div>

                    <form id="cv-upload-form" class="job-setup-form">
                        <span class="job-field-label">Upload New Resume</span>
                        <input type="file" id="cv-file-input" name="cv" accept=".pdf,.docx,.txt,.md" class="sr-only">
                        <label id="cv-dropzone" for="cv-file-input" class="job-dropzone">
                            <span class="job-dropzone-icon"><uk-icon icon="cloud-upload"></uk-icon></span>
                            <span class="job-dropzone-text">Drag &amp; drop file here, or <span class="job-dropzone-link">browse your files</span></span>
                            <span class="job-dropzone-hint">PDF, DOCX, TXT, MD &middot; Max 15MB</span>
                        </label>

                        <div class="job-field-row">
                            <div class="job-field">
                                <label for="cv-designation" class="job-field-label">Designation / Role Title <span class="job-field-optional">(optional)</span></label>
                                <input type="text" id="cv-designation" class="job-input" placeholder="e.g. Senior Backend Engineer">
                            </div>
                            <button type="submit" id="cv-upload-submit" class="job-btn job-btn--ghost" disabled><uk-icon icon="upload"></uk-icon><span id="cv-upload-label">Upload CV</span></button>
                        </div>

                        <div id="cv-file-chip" class="job-chip hidden">
                            <uk-icon icon="file-text" class="job-chip-icon"></uk-icon>
                            <div class="job-chip-meta">
                                <div id="cv-file-name"></div>
                                <div id="cv-file-size"></div>
                            </div>
                            <button type="button" id="cv-file-remove" class="job-chip-remove" title="Remove file"><uk-icon icon="close"></uk-icon></button>
                        </div>

                        <div id="cv-upload-error" class="job-form-error hidden"><uk-icon icon="warning"></uk-icon><span id="cv-upload-error-text"></span></div>
                    </form>

                    <div id="cv-list-container" class="job-cards-list"></div>
                </section>

                <section id="job-view-profile" class="job-setup-card" aria-labelledby="job-prefs-heading">
                    <div class="job-setup-card-head">
                        <h2 id="job-prefs-heading" class="job-setup-card-title"><uk-icon icon="user"></uk-icon> Search Preferences</h2>
                        <span class="job-setup-pill job-setup-pill--muted">Global defaults</span>
                    </div>

                    <form id="profile-form" class="job-setup-form">
                        <p class="job-setup-hint">These preferences apply to every CV. Save changes before starting a search.</p>

                        <div class="job-field">
                            <label for="profile-locations" class="job-field-label">Preferred Locations (comma separated)</label>
                            <input type="text" id="profile-locations" class="job-input" placeholder="e.g. Vienna, Remote, Berlin">
                        </div>

                        <div class="job-field">
                            <span class="job-field-label">Work Mode</span>
                            <div id="profile-work-mode" class="job-check-group">
                                <label class="job-check-pill"><input type="checkbox" value="remote"><span class="job-check-box"></span><span class="job-check-text">Remote</span></label>
                                <label class="job-check-pill"><input type="checkbox" value="hybrid"><span class="job-check-box"></span><span class="job-check-text">Hybrid</span></label>
                                <label class="job-check-pill"><input type="checkbox" value="on_site"><span class="job-check-box"></span><span class="job-check-text">On-site</span></label>
                            </div>
                        </div>

                        <div class="job-field">
                            <span class="job-field-label">Employment Type</span>
                            <div id="profile-employment" class="job-check-group">
                                <label class="job-check-pill"><input type="checkbox" value="full-time"><span class="job-check-box"></span><span class="job-check-text">Full-time</span></label>
                                <label class="job-check-pill"><input type="checkbox" value="part-time"><span class="job-check-box"></span><span class="job-check-text">Part-time</span></label>
                            </div>
                        </div>

                        <div class="job-field-row job-field-row--2">
                            <div class="job-field">
                                <label for="profile-salary-min" class="job-field-label">Minimum Base Salary</label>
                                <div class="job-input-wrap">
                                    <input type="text" id="profile-salary-min" class="job-input" placeholder="70000">
                                    <span class="job-input-suffix">/yr</span>
                                </div>
                            </div>
                            <div class="job-field">
                                <label for="profile-salary-currency" class="job-field-label">Currency</label>
                                <input type="text" id="profile-salary-currency" class="job-input" placeholder="EUR" maxlength="3">
                            </div>
                        </div>

                        <div class="job-field">
                            <label for="profile-free-text" class="job-field-label">Free-text preferences</label>
                            <textarea id="profile-free-text" class="job-input job-textarea" rows="3" placeholder="Anything else you prefer, e.g. remote-first culture, modern CI/CD stacks, no legacy maintenance..."></textarea>
                        </div>

                        <div class="job-card-foot">
                            <span id="profile-complete-badge" class="job-foot-status"></span>
                            <div class="job-foot-actions">
                                <button type="button" id="profile-discard" class="job-btn job-btn--ghost">Discard changes</button>
                                <button type="submit" class="job-btn job-btn--outline">Save Profile</button>
                            </div>
                        </div>
                    </form>
                </section>
            </div>

            <section id="job-view-registry" class="job-setup-card" aria-labelledby="job-sources-heading">
                <div class="job-setup-card-head">
                    <h2 id="job-sources-heading" class="job-setup-card-title"><uk-icon icon="database"></uk-icon> Sources</h2>
                    <span class="job-setup-pill job-setup-pill--muted">Listing URL templates</span>
                </div>

                <form id="registry-form" class="job-setup-form job-registry-form">
                    <label class="job-field-label" for="reg-url">Add / Edit Source</label>
                    <input type="text" id="reg-url" class="job-input job-input--mono" placeholder="https://www.karriere.at/jobs?keywords={job_title}&locations={location}">
                    <div class="job-field-row job-field-row--2">
                        <div class="job-field">
                            <label for="reg-job-title" class="job-field-label">Job title values</label>
                            <input type="text" id="reg-job-title" class="job-input job-input--mono" placeholder="php, go, cloud engineer">
                        </div>
                        <div class="job-field">
                            <label for="reg-location" class="job-field-label">Location values</label>
                            <input type="text" id="reg-location" class="job-input job-input--mono" placeholder="wien und umgebung, remote">
                        </div>
                    </div>
                    <div class="job-foot-actions">
                        <button type="submit" class="job-btn job-btn--outline"><span id="reg-save-label">Add source</span></button>
                        <button type="button" id="reg-cancel" class="job-btn job-btn--ghost">Cancel edit</button>
                    </div>
                    <p class="job-setup-hint">Use <code>{job_title}</code> and <code>{location}</code> in the URL where you want values substituted. Separate values with commas.</p>
                </form>

                <div id="registry-list-container" class="job-cards-list"></div>
            </section>
        </div>

        <footer class="jobs-setup-footer">
            <span class="jobs-setup-sync"><span class="jobs-setup-sync-dot"></span> Saves apply per section</span>
        </footer>
    </dialog>
</div>
