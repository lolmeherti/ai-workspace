<div class="flex-1 flex h-full overflow-hidden text-xs bg-[#040810]">
    <div class="w-1/3 border-r border-slate-850 flex flex-col h-full bg-[#080d16]/80 backdrop-blur-md">
        <div class="p-6 border-b border-slate-850 bg-[#0d1321]/60 select-none shrink-0">
            <h1 class="text-sm font-bold text-slate-100 flex items-center gap-2 tracking-wide uppercase">
                <uk-icon icon="mail" class="w-4 h-4 text-cyan-400"></uk-icon>
                Com Deck
            </h1>
            <p class="text-[10px] text-slate-400 mt-1">Select an indexed mail terminal to synchronize incoming links.</p>
            
            <div class="mt-4">
                <select id="workspace-account-select" onchange="window.loadInbox(this.value, 1)" class="w-full bg-[#0b1120] border border-cyan-500/10 hover:border-cyan-500/30 rounded-lg px-3 py-2 text-slate-200 outline-none transition-all text-xs font-mono font-bold tracking-wider">
                    <option value="">[Offline Mail Terminal]</option>
                    <?php
                    $wsAccounts = $db->query("SELECT id, label, email_address FROM email_accounts ORDER BY id DESC");
                    foreach ($wsAccounts as $wsAcc):
                    ?>
                        <option value="<?php echo (int)$wsAcc['id']; ?>">
                            [ONLINE] <?php echo htmlspecialchars($wsAcc['label'] . " <" . $wsAcc['email_address'] . ">"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="email-list-container" class="flex-1 overflow-y-auto p-4 space-y-3">
            <div class="text-center py-20 text-slate-500 flex flex-col items-center justify-center gap-3 select-none">
                <uk-icon icon="mail" class="w-10 h-12 text-slate-700 opacity-30"></uk-icon>
                <p class="text-[10px] tracking-widest uppercase font-bold text-slate-600">Comms Link Offline</p>
            </div>
        </div>

        <div id="email-pagination-container" class="p-4 border-t border-slate-850 flex justify-between items-center bg-[#090e18]/80 hidden select-none shrink-0">
            <button id="email-prev-btn" onclick="window.navigateEmails(-1)" class="px-3.5 py-1.5 bg-[#091124] hover:bg-[#0c162f] text-slate-300 hover:text-cyan-400 border border-slate-800 hover:border-cyan-500/30 rounded-lg text-[10px] font-extrabold uppercase tracking-widest transition-all cursor-pointer outline-none">Prev</button>
            <span id="email-page-num" class="text-slate-400 font-mono font-extrabold text-[10px] tracking-widest bg-slate-950/60 border border-slate-900 rounded px-2.5 py-1">PAGE 1 / 1</span>
            <button id="email-next-btn" onclick="window.navigateEmails(1)" class="px-3.5 py-1.5 bg-[#091124] hover:bg-[#0c162f] text-slate-300 hover:text-cyan-400 border border-slate-800 hover:border-cyan-500/30 rounded-lg text-[10px] font-extrabold uppercase tracking-widest transition-all cursor-pointer outline-none">Next</button>
        </div>
    </div>

    <div class="flex-1 flex flex-col h-full bg-[#040810] relative">
        <div id="email-reader-pane" class="flex-1 flex flex-col h-full overflow-hidden hidden">
            <div class="p-6 border-b border-slate-850 bg-[#0d1321]/40 shrink-0">
                <div class="flex justify-between items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <h2 id="read-subject" class="text-base font-bold text-slate-100 tracking-wide break-words"></h2>
                        <div class="flex flex-col gap-1.5 mt-3 text-[10px] text-slate-400 font-mono">
                            <div class="flex items-center gap-1.5"><span class="text-slate-500 uppercase font-sans tracking-widest font-extrabold">FROM:</span> <span id="read-from" class="text-cyan-400 font-bold bg-cyan-950/25 border border-cyan-500/10 px-2 py-0.5 rounded animate-fade-in"></span></div>
                            <div class="flex items-center gap-1.5"><span class="text-slate-500 uppercase font-sans tracking-widest font-extrabold">DATE:</span> <span id="read-date" class="text-slate-300 font-medium animate-fade-in"></span></div>
                        </div>
                    </div>
                    <button onclick="window.toggleReplyForm()" class="px-4 py-2 bg-cyan-950/40 hover:bg-cyan-900/60 text-cyan-400 border border-cyan-500/30 hover:border-cyan-400/50 rounded-lg cursor-pointer transition-all outline-none shrink-0 flex items-center gap-2 font-bold tracking-wider uppercase text-[10px] shadow-lg shadow-cyan-950/10">
                        <uk-icon icon="reply" class="w-3.5 h-3.5"></uk-icon> Reply
                    </button>
                </div>
            </div>

            <div class="flex-1 p-6 overflow-y-auto flex flex-col min-h-0 space-y-4">
                <div class="flex-1 bg-white rounded-xl overflow-hidden border border-slate-850 shadow-[inset_0_0_15px_rgba(0,0,0,0.8)] relative min-h-[220px]">
                    <iframe id="email-body-iframe" class="w-full h-full border-0 absolute inset-0 bg-white animate-fade-in"></iframe>
                </div>

                <div id="reply-form-container" class="mt-4 border border-cyan-500/15 rounded-xl p-5 bg-[#091124]/90 shadow-[0_0_20px_rgba(6,182,212,0.05)] hidden shrink-0 text-left">
                    <h4 class="text-[10px] font-extrabold text-cyan-400 uppercase tracking-widest mb-4 flex items-center gap-1.5">
                        <uk-icon icon="reply" class="w-3.5 h-3.5"></uk-icon> Transmission Output
                    </h4>
                    <form id="email-reply-form" onsubmit="window.submitEmailReply(event)" class="space-y-4">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Recipient Header</label>
                            <input type="text" id="reply-to-input" required readonly class="w-full bg-[#060b13] border border-slate-850 rounded-lg px-3 py-2 text-slate-400 outline-none font-mono tracking-wider">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Transmission Subject</label>
                            <input type="text" id="reply-subject-input" required class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:border-cyan-500/30 transition-colors">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Message Crypt</label>
                            <textarea id="reply-body-input" required rows="6" class="w-full bg-[#0b1120] border border-slate-800 rounded-lg px-3 py-2.5 text-slate-200 focus:outline-none focus:border-cyan-500/30 transition-colors font-mono leading-relaxed text-[11px]"></textarea>
                        </div>
                        <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-850">
                            <button type="button" onclick="window.toggleReplyForm()" class="px-4 py-2 bg-slate-800 hover:bg-slate-750 border border-slate-700 rounded-lg text-slate-300 font-bold transition-all cursor-pointer outline-none">Cancel</button>
                            <button type="submit" id="reply-submit-btn" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 border border-cyan-500 rounded-lg text-white font-bold transition-all cursor-pointer outline-none">Reply</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="email-reader-empty" class="flex-1 flex flex-col justify-center items-center text-slate-500 select-none">
            <uk-icon icon="mail" class="w-12 h-12 text-slate-800 opacity-20 mb-3"></uk-icon>
            <p class="text-[10px] font-bold tracking-widest uppercase text-slate-600">Select link-stream to render content</p>
        </div>
    </div>
</div>
