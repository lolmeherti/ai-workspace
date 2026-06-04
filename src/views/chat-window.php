<section class="flex-1 flex flex-col h-full relative bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0d1526] via-[#070b14] to-[#070b14]">
    
    <header class="h-16 border-b border-slate-800/80 flex items-center justify-between px-6 glass-panel backdrop-blur-md z-10">
        <h2 class="m-0 text-base font-semibold truncate text-slate-100 flex items-center gap-3">
            <uk-icon icon="message-square" class="w-5 h-5 text-cyan-500"></uk-icon>
            <?php echo htmlspecialchars($activeSessionTitle); ?>
        </h2>
        <div>
            <?php if (!$status->all_operational): ?>
                <span class="text-xs font-bold px-3 py-1 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center gap-2 shadow-[0_0_10px_rgba(244,63,94,0.2)]">
                    <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span> Offline
                </span>
            <?php else: ?>
                <span class="text-xs font-bold px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.2)]">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Operational
                </span>
            <?php endif; ?>
        </div>
    </header>

    <!-- Chat Flow Window -->
    <div class="flex-1 overflow-y-auto p-6 space-y-8" id="chatWindow">
        <?php if (empty($history)): ?>
            <div class="flex flex-col items-center justify-center text-center h-full py-20 opacity-80">
                <div class="w-20 h-20 mb-6 rounded-full bg-gradient-to-tr from-cyan-500/20 to-blue-500/20 flex items-center justify-center border border-cyan-500/30 shadow-[0_0_30px_rgba(6,182,212,0.15)]">
                    <uk-icon icon="bot" class="w-10 h-10 text-cyan-400"></uk-icon>
                </div>
                <h3 class="text-2xl font-bold tracking-tight text-white mb-2">How can I assist you today?</h3>
                <p class="text-sm text-slate-400 max-w-sm">Enter a prompt, ask a question, or attach an image to start the conversation.</p>
            </div>
        <?php else: ?>
            <?php foreach ($history as $msg): ?>
                <div class="flex flex-col w-full max-w-[92%] mx-auto space-y-1 <?php echo $msg['role'] === 'user' ? 'items-end' : 'items-start'; ?>">
                    
                    <!-- Metadata Row with Aligned Copy Button -->
                    <div class="flex items-center gap-2 <?php echo $msg['role'] === 'user' ? 'flex-row-reverse mr-1' : 'ml-1'; ?>">
                        <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">
                            <?php echo $msg['role'] === 'user' ? 'You' : 'Assistant'; ?>
                        </span>
                        <button class="text-slate-500 hover:text-cyan-400 p-0.5 rounded transition-colors duration-150 cursor-pointer flex items-center justify-center" 
                                onclick="copyToClipboard(this)" 
                                title="Copy message">
                            <uk-icon icon="copy" class="w-3.5 h-3.5"></uk-icon>
                        </button>
                    </div>
                    
                    <!-- Bubble Content Container -->
                    <div class="<?php echo $msg['role'] === 'user' ? 'chat-user rounded-2xl rounded-tr-sm' : 'chat-assistant rounded-2xl rounded-tl-sm markdown-content'; ?> px-5 py-4 text-[0.95rem] leading-relaxed max-w-[85%]"
                         data-raw="<?php echo htmlspecialchars($msg['message']); ?>">
                        <?php if (!empty($msg['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($msg['image_path']); ?>" class="max-w-xs rounded-lg mb-3 border border-white/20 shadow-md block" alt="Uploaded image">
                        <?php endif; ?>
                        
                        <?php if ($msg['role'] === 'assistant'): ?>
                            <div class="markdown-rendered" data-markdown="<?php echo htmlspecialchars($msg['message']); ?>"></div>
                        <?php else: ?>
                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Input Footer Controls -->
    <div class="p-4 border-t border-slate-800/80 glass-panel backdrop-blur-md relative z-10">
        <div class="max-w-[92%] mx-auto relative">
            
            <div id="image-preview-container" class="hidden absolute bottom-full left-0 mb-3 p-2 bg-[#0f172a] border border-slate-700 rounded-lg flex items-center gap-3 shadow-xl">
                <div class="relative">
                    <img id="image-preview" src="" class="h-16 w-16 object-cover rounded-md border border-slate-600" alt="Preview">
                    <button type="button" class="absolute -top-2 -right-2 bg-rose-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs hover:bg-rose-400 shadow-md" onclick="removeImage()">×</button>
                </div>
                <span class="text-xs text-slate-300 font-medium pr-2">Image attached</span>
            </div>
            
            <form id="chatForm" onsubmit="handleChatSubmit(event)" class="relative">
                <input type="hidden" name="session_id" value="<?php echo $sessionId; ?>">
                <input type="file" id="imageInput" name="image" accept="image/*" class="hidden" onchange="previewImage(this)">
                
                <div class="flex w-full items-end gap-2 bg-[#0f172a] border border-slate-700 rounded-xl p-1.5 focus-within:border-cyan-500 focus-within:ring-1 focus-within:ring-cyan-500 transition-all shadow-inner" <?php echo $status->all_operational ? '' : 'disabled'; ?>>
                    <button type="button" class="shrink-0 p-2.5 text-slate-400 hover:text-cyan-400 transition-colors rounded-lg hover:bg-slate-800" onclick="document.getElementById('imageInput').click()" title="Attach Image">
                        <uk-icon icon="paperclip" class="w-5 h-5"></uk-icon>
                    </button>
                    
                    <textarea id="q" name="q" rows="1" class="flex-1 bg-transparent border-none text-slate-100 placeholder-slate-500 resize-none py-2.5 focus:outline-none focus:ring-0 max-h-32 min-h-[44px]" placeholder="Message AI Assistant..." required autocomplete="off" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                    
                    <button type="submit" class="btn-futuristic shrink-0 px-4 py-2 rounded-lg font-semibold flex items-center gap-2 h-[44px]">
                        Send <uk-icon icon="send" class="w-4 h-4"></uk-icon>
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>