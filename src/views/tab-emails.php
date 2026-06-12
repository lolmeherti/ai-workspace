<div id="panel-emails" class="hidden h-full flex flex-col bg-[#070b14]/40">
    <div class="p-4 border-b border-slate-800/50 flex justify-between items-center bg-[#0d1321]/30">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5 m-0">
            <uk-icon icon="mail" class="w-3.5 h-3.5 text-cyan-400"></uk-icon> Linked Mailboxes
        </h3>
        <button uk-toggle="target: #add-email-modal" type="button" class="flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-cyan-400 bg-cyan-950/40 hover:bg-cyan-900/60 border border-cyan-500/30 rounded transition-colors cursor-pointer outline-none">
            <uk-icon icon="plus" class="w-3 h-3"></uk-icon> Add Account
        </button>
    </div>

    <div class="p-4 border-b border-slate-800/30 space-y-3">
        <button onclick="window.triggerUnifiedBriefing()" type="button" class="w-full flex items-center justify-center gap-2 py-2.5 rounded-lg font-bold text-xs bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white shadow-lg shadow-cyan-950/20 border border-cyan-500/30 cursor-pointer outline-none transition-all">
            <uk-icon icon="sparkles" class="w-3.5 h-3.5"></uk-icon> Generate Daily Briefing
        </button>

        <!-- Redesigned Parameter Toggle -->
        <label for="briefing-include-read" class="flex items-center justify-between px-3 py-2 bg-[#0b101c]/30 border border-slate-800/60 hover:border-cyan-500/20 rounded-lg cursor-pointer transition-all select-none group">
            <input type="checkbox" id="briefing-include-read" class="sr-only peer">
            
            <div class="flex items-center gap-2 text-slate-500 peer-checked:text-cyan-400 transition-colors duration-200">
                <uk-icon icon="history" class="w-3.5 h-3.5"></uk-icon>
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold tracking-wider uppercase">Include Read Mail</span>
                    <span class="text-[8px] text-slate-500/85 font-medium uppercase tracking-tight peer-checked:text-cyan-600/70 transition-colors duration-200">Scan historical inbox</span>
                </div>
            </div>

            <!-- Custom Switch Track & Indicator -->
            <div class="relative w-8 h-4 bg-slate-900 border border-slate-800 rounded-full transition-all peer-checked:bg-cyan-950/40 peer-checked:border-cyan-500/30 after:content-[''] after:absolute after:top-[2.5px] after:left-[2.5px] after:bg-slate-600 peer-checked:after:bg-cyan-400 after:rounded-full after:h-2.5 after:w-2.5 after:transition-all peer-checked:after:translate-x-4"></div>
        </label>
    </div>

    <div class="flex-1 overflow-y-auto p-4 space-y-3">
        <?php
        $mailboxes = $db->query("SELECT * FROM email_accounts ORDER BY id DESC");
        if (empty($mailboxes)):
        ?>
            <div class="text-center py-8 text-slate-500">
                <uk-icon icon="mail" class="w-8 h-8 opacity-20 mb-2"></uk-icon>
                <p class="text-xs">No email accounts linked yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($mailboxes as $mailbox): ?>
                <div class="bg-[#0f172a]/60 border border-slate-800/60 hover:border-slate-700/60 rounded-lg p-3 flex justify-between items-start transition-all relative group shadow-sm">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold text-slate-200 truncate flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                            <?php echo htmlspecialchars($mailbox['label']); ?>
                        </div>
                        <div class="text-[10px] text-slate-400 truncate mt-0.5 font-mono"><?php echo htmlspecialchars($mailbox['email_address']); ?></div>
                        <span class="inline-block px-1.5 py-0.5 text-[9px] font-semibold tracking-wider uppercase bg-slate-850 border border-slate-800 text-slate-400 rounded mt-1.5 font-mono">
                            <?php echo htmlspecialchars($mailbox['provider']); ?>
                        </span>
                    </div>
                    <form method="POST" action="index.php" onsubmit="return confirm('Disconnect this email account?');" class="ml-2 shrink-0">
                        <input type="hidden" name="action" value="delete_email_account">
                        <input type="hidden" name="account_id" value="<?php echo (int)$mailbox['id']; ?>">
                        <button type="submit" class="text-slate-500 hover:text-rose-400 transition-colors p-1 cursor-pointer bg-transparent border-0 outline-none">
                            <uk-icon icon="trash-2" class="w-3.5 h-3.5"></uk-icon>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="add-email-modal" uk-modal class="uk-flex-top">
    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical bg-[#090d16] border border-slate-850 rounded-xl max-w-md p-6 shadow-2xl">
        <button class="uk-modal-close-default text-slate-500 hover:text-slate-300 cursor-pointer" type="button" uk-close></button>
        <h2 class="text-base font-bold text-slate-100 flex items-center gap-2 mb-4 border-b border-slate-850 pb-3">
            <uk-icon icon="mail" class="text-cyan-400"></uk-icon> Link New Email Account
        </h2>
        <form method="POST" action="index.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="add_email_account">
            
            <div>
                <label class="block text-slate-400 font-medium mb-1.5">Custom Label</label>
                <input type="text" name="label" required placeholder="e.g. Work Gmail, Personal Mail" class="w-full bg-[#0b101c] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/50">
            </div>

            <div>
                <label class="block text-slate-400 font-medium mb-1.5">Mail Provider</label>
                <select name="provider" id="email-provider-select" required onchange="window.toggleCustomImapFields(this.value)" class="w-full bg-[#0b101c] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/50">
                    <option value="Gmail">Gmail</option>
                    <option value="Yandex">Yandex</option>
                    <option value="Yahoo">Yahoo</option>
                    <option value="Custom IMAP">Custom IMAP</option>
                </select>
            </div>

            <div>
                <label class="block text-slate-400 font-medium mb-1.5">Email Address</label>
                <input type="email" name="email_address" required placeholder="name@domain.com" class="w-full bg-[#0b101c] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/50 font-mono">
            </div>

            <div>
                <label class="block text-slate-400 font-medium mb-1.5">App Password</label>
                <input type="password" name="app_password" required placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢" class="w-full bg-[#0b101c] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/50 font-mono">
                <p class="text-[10px] text-slate-500 mt-1">For Gmail/Yahoo, generate an "App Password" in your account security settings. Do not use your primary password.</p>
            </div>

            <div id="custom-imap-wrapper" class="hidden border-t border-slate-850 pt-4 space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label class="block text-slate-400 font-medium mb-1.5">IMAP Host</label>
                        <input type="text" name="imap_host" placeholder="imap.domain.com" class="w-full bg-[#0b101c] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-slate-400 font-medium mb-1.5">IMAP Port</label>
                        <input type="number" name="imap_port" placeholder="993" class="w-full bg-[#0b101c] border border-slate-800 rounded-lg px-3 py-2 text-slate-200 focus:outline-none font-mono">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-850">
                <button type="button" class="uk-modal-close px-4 py-2 bg-slate-800 hover:bg-slate-750 border border-slate-700 rounded-lg text-slate-300 font-bold transition-colors cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 border border-cyan-500 rounded-lg text-white font-bold transition-colors cursor-pointer">Connect Account</button>
            </div>
        </form>
    </div>
</div>
