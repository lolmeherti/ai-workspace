import test from 'node:test';
import assert from 'node:assert/strict';
import { mount, fixture, json, deferred, settle } from './dom.mjs';
const dom = mount(fixture('session_id=3&tab=jobs'));
const views = await import('../../src/js/jobs/jobViews.js');
const details = await import('../../src/js/jobs/jobDetails.js');
details.initDetails();
test('CV selection survives refresh even when another CV is the default', async () => {
    const cvs = [{ uuid: 'first', designation: 'First', active_flag: 1 }, { uuid: 'chosen', designation: 'Chosen' }];
    await views.refreshJobCvSelect(cvs); document.getElementById('job-cv-select').value = 'chosen';
    await views.refreshJobCvSelect(cvs); assert.equal(document.getElementById('job-cv-select').value, 'chosen');
});
test('opening setup and progress keeps the selected-job pane available', () => {
    views.switchJobView('profile'); assert.equal(document.getElementById('job-setup').open, true);
    assert.ok(document.querySelector('#job-setup #job-view-cvs')); assert.ok(document.querySelector('#job-setup #job-view-profile'));
    views.switchJobView('progress'); assert.equal(document.getElementById('job-run-activity').open, true);
    assert.equal(document.getElementById('job-view-details').classList.contains('hidden'), false);
});
test('late job details cannot replace the latest selection', async () => {
    const first = deferred(), second = deferred();
    globalThis.fetch = url => String(url).includes('uuid=first') ? first.promise : second.promise;
    const p1 = details.selectJob('first'); await settle();
    const p2 = details.selectJob('second'); await settle();
    second.resolve(json({ status: 'success', job: { uuid: 'second', title: 'Second role', company: 'B', state: 'interested' } })); await p2;
    first.resolve(json({ status: 'success', job: { uuid: 'first', title: 'Old role', company: 'A', state: 'unread' } })); await p1;
    assert.equal(document.querySelector('.job-read-view h2').textContent, 'Second role');
    assert.match(document.getElementById('job-details-container').textContent, /Record application/);
    assert.equal(document.getElementById('job-edit-form'), null);
});
test.after(() => dom.window.close());
