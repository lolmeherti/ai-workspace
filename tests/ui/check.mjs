import { readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';
import { fixture } from './dom.mjs';
function files(dir) { return readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory() ? files(join(dir, entry.name)) : [join(dir, entry.name)]); }
let failures = 0;
for (const path of files('src/js').filter(p => p.endsWith('.js'))) {
    const result = spawnSync(process.execPath, ['--check', path], { encoding: 'utf8' });
    if (result.status) { process.stderr.write(result.stderr); failures++; }
}
const php = process.env.LOCALSY_PHP || 'php';
// Keep this gate useful after the branch is committed, without depending on a dirty diff.
for (const path of [...files('src'), ...files('tests/ui')].filter(p => p.endsWith('.php') && !p.includes('/vendor/'))) {
    const result = spawnSync(php, ['-l', path], { encoding: 'utf8' });
    if (result.status) { process.stderr.write(result.stdout + result.stderr); failures++; }
}
const document = new JSDOM(fixture()).window.document;
for (const script of document.querySelectorAll('script:not([src])')) {
    if (script.type === 'module' || script.type === 'application/json') continue;
    try { new vm.Script(script.textContent); } catch (e) { console.error(e); failures++; }
}
console.log(failures ? `${failures} syntax check(s) failed.` : 'JavaScript modules, PHP source/fixtures, and rendered inline scripts pass syntax checks.');
process.exitCode = failures ? 1 : 0;
