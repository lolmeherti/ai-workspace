import { startPreview } from './browser.mjs';
import { mkdir } from 'node:fs/promises';

const preview = await startPreview();
try {
    const page = await preview.browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.on('pageerror', error => console.log('Page error:', error.message));
    const response = await page.goto(preview.baseURL + '/index.php?session_id=3&tab=chats', { waitUntil: 'domcontentloaded' });
    console.log('Preview HTTP', response.status());
    await page.waitForSelector('#chatForm', { timeout: 10000 });
    await page.waitForFunction(() => typeof window.switchSidebarTab === 'function');
    await page.waitForTimeout(1200);
    await mkdir('docs/ui/screenshots', { recursive: true });
    const prefix = process.argv[2] || 'current';
    await page.screenshot({ path: 'docs/ui/screenshots/' + prefix + '-chat.png' });
    await page.click('#tab-btn-jobs');
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'docs/ui/screenshots/' + prefix + '-jobs.png' });
    console.log('Captured', prefix);
} finally {
    console.log(preview.logs().split('\n').filter(line => /Warning|Fatal|Parse/.test(line)).join('\n'));
    await preview.close();
}
