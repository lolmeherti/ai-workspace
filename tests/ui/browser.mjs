import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { chromium } from 'playwright';
import { resolve } from 'node:path';

export async function startPreview() {
    const port = 19000 + Math.floor(Math.random() * 10000);
    const server = spawn(process.env.LOCALSY_PHP || 'php',
        ['-S', '127.0.0.1:' + port, '-t', 'src', 'tests/ui/preview.php'],
        { cwd: resolve(import.meta.dirname, '../..'), stdio: ['ignore', 'pipe', 'pipe'] });
    let logs = '';
    server.stderr.on('data', chunk => { logs += chunk; });
    let startupTimer;
    let browser;
    try {
    await Promise.race([
        once(server.stderr, 'data'),
        once(server, 'error').then(([error]) => { throw error; }),
        new Promise((_, reject) => { startupTimer = setTimeout(() => reject(new Error('Preview failed to start: ' + logs)), 5000); })
    ]);
    clearTimeout(startupTimer);
    browser = await chromium.launch({
        executablePath: process.env.LOCALSY_CHROMIUM || undefined,
        headless: true
    });
    } catch (error) { clearTimeout(startupTimer); server.kill(); throw error; }
    return {
        browser,
        baseURL: 'http://127.0.0.1:' + port,
        logs: () => logs,
        close: async () => { await browser.close(); server.kill(); }
    };
}
