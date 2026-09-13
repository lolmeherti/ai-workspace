<?php
// First-run model onboarding. Rendered when the launcher reports 0 downloaded
// models (or forced via ?onboarding=1). Shows 3 suggested model cards and drives
// the first download through the existing switch machinery.

// Build 3 distinct model cards (one per model_id, highest ctx per model, top 3).
$cards = [];
foreach (($modelsList ?? []) as $m) {
    $mid = $m['model_id'] ?? '';
    if ($mid === '') continue;
    if (!isset($cards[$mid]) || (int)($m['ctx_size'] ?? 0) > (int)($cards[$mid]['ctx_size'] ?? 0)) {
        $cards[$mid] = $m;
    }
}
$cards = array_values($cards);
usort($cards, fn($a, $b) => (int)($b['ctx_size'] ?? 0) <=> (int)($a['ctx_size'] ?? 0));
$cards = array_slice($cards, 0, 3);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Localsy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        slate: { 750: '#2a3b55', 850: '#182236' },
                    },
                },
            },
        };
    </script>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="h-screen w-screen overflow-hidden antialiased bg-[#040810] text-slate-200">
    <div class="h-full w-full flex items-center justify-center p-8">
        <div class="w-full max-w-3xl">
            <div class="text-center mb-10">
                <h1 class="text-3xl font-bold tracking-tight text-white">Welcome to Localsy</h1>
                <p class="text-slate-400 mt-2 text-sm">Choose your first model. It downloads once, then runs entirely on your GPU.</p>
            </div>

            <div id="onboarding-cards" class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <?php if (empty($cards)): ?>
                    <div class="col-span-3 text-center text-slate-400 py-12">
                        <p class="text-sm">Couldn't reach the launcher to list models.</p>
                        <p class="text-xs mt-2 text-slate-500">Make sure Localsy is running, then refresh.</p>
                        <button onclick="location.reload()" class="mt-4 px-4 py-2 rounded-lg text-sm border border-slate-700 text-slate-300 hover:bg-slate-800 transition-colors">Retry</button>
                    </div>
                <?php else: foreach ($cards as $m):
                    $mid = $m['model_id'] ?? '';
                    $name = $m['name'] ?? $mid;
                    $ctx = (int)($m['ctx_size'] ?? 0);
                    $vram = $m['vram_group'] ?? '';
                ?>
                    <div class="rounded-xl border border-slate-800 bg-slate-900/40 p-5 flex flex-col hover:border-cyan-500/40 transition-colors">
                        <div class="text-xs uppercase tracking-wider text-slate-500 mb-1"><?php echo htmlspecialchars($vram); ?></div>
                        <h2 class="text-base font-semibold text-white leading-snug mb-2"><?php echo htmlspecialchars($name); ?></h2>
                        <p class="text-sm text-slate-400 mb-4"><?php echo $ctx >= 1000 ? number_format($ctx) . ' ctx' : ''; ?></p>
                        <button type="button"
                                data-model-id="<?php echo htmlspecialchars($mid); ?>"
                                data-ctx="<?php echo $ctx; ?>"
                                class="mt-auto px-4 py-2 rounded-lg text-sm font-medium border border-cyan-500/40 text-cyan-300 hover:bg-cyan-500/10 transition-colors">
                            Choose this model
                        </button>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <div id="onboarding-progress" class="hidden mt-8 rounded-xl border border-slate-800 bg-slate-900/40 p-6">
                <div class="flex items-center gap-3">
                    <span id="onboarding-spinner" class="switch-spinner"></span>
                    <span id="onboarding-status" class="text-sm text-slate-300">Preparing…</span>
                </div>
                <progress id="onboarding-bar" class="w-full h-2 rounded-full mt-4" max="100" value="0"></progress>
                <div class="flex justify-between items-center mt-2">
                    <span id="onboarding-pct" class="text-sm text-cyan-400 font-semibold"></span>
                    <button type="button" id="onboarding-cancel" class="hidden text-xs px-2 py-1 rounded border border-rose-500/40 text-rose-400 hover:bg-rose-500/10 transition-colors">Cancel download</button>
                </div>
                <div id="onboarding-error" class="hidden text-xs text-red-400 mt-3"></div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const stageLabel = {
            resolving: 'Resolving model…',
            downloading: 'Downloading model…',
            starting: 'Starting llama-server… (large models take a moment)',
        };
        const cards = document.getElementById('onboarding-cards');
        const progress = document.getElementById('onboarding-progress');
        const status = document.getElementById('onboarding-status');
        const bar = document.getElementById('onboarding-bar');
        const pct = document.getElementById('onboarding-pct');
        const cancel = document.getElementById('onboarding-cancel');
        const error = document.getElementById('onboarding-error');
        let polling = false;

        function showError(msg) {
            error.textContent = msg;
            error.classList.remove('hidden');
            status.textContent = 'Something went wrong.';
            bar.classList.add('hidden');
            pct.classList.add('hidden');
            if (cancel) cancel.classList.add('hidden');
            cards.classList.remove('hidden');
        }

        function updateProgress(st) {
            status.textContent = stageLabel[st.stage] || 'Working…';
            if (st.stage === 'downloading') {
                bar.classList.remove('hidden');
                pct.classList.remove('hidden');
                if (cancel) cancel.classList.remove('hidden');
                const v = Math.round(Number(st.progress) || 0);
                bar.value = v;
                pct.textContent = v + '%';
            } else {
                bar.classList.add('hidden');
                pct.classList.add('hidden');
                if (cancel) cancel.classList.add('hidden');
            }
        }

        function pollSwitch() {
            if (polling) return;
            polling = true;
            const tick = async () => {
                try {
                    const r = await fetch('index.php?api_action=get_switch_status', { headers: { 'Accept': 'application/json' } });
                    const st = await r.json();
                    if (st.active === false) {
                        polling = false;
                        if (st.stage === 'loaded') {
                            window.location.href = 'index.php';
                        } else {
                            showError(st.error || 'Model switch failed.');
                        }
                        return;
                    }
                    updateProgress(st);
                    setTimeout(tick, 2000);
                } catch (_) {
                    setTimeout(tick, 3000);
                }
            };
            tick();
        }

        document.querySelectorAll('[data-model-id]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.modelId;
                const ctx = btn.dataset.ctx || '0';
                cards.classList.add('hidden');
                progress.classList.remove('hidden');
                progress.classList.add('block');
                status.textContent = 'Starting download…';
                error.classList.add('hidden');
                try {
                    const resp = await fetch('index.php', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ save_settings: '1', model_id: id, ctx_size: ctx }),
                    });
                    const data = await resp.json();
                    if (data.status === 'switching') {
                        status.textContent = 'Switching to ' + (data.name || id) + '…';
                        pollSwitch();
                    } else if (data.status === 'busy') {
                        updateProgress({ stage: data.stage || 'downloading', progress: data.progress || 0 });
                        pollSwitch();
                    } else {
                        showError(data.message || 'Failed to start the download.');
                    }
                } catch (err) {
                    showError('Network error: ' + err.message);
                }
            });
        });

        if (cancel) {
            cancel.addEventListener('click', () => {
                fetch('index.php?api_action=cancel_switch', { headers: { 'Accept': 'application/json' } }).catch(() => {});
                status.textContent = 'Cancelling download…';
                cancel.classList.add('hidden');
            });
        }
    })();
    </script>
</body>
</html>
