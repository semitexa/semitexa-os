import { expect, test, type Page } from '@playwright/test';

/**
 * tk-kh-os-shell-kiss: the Chill process list is pushed, not polled.
 *
 * The shell writes its own head, so it never got the page's KISS session, the
 * import map or event-runtime: when feeds moved onto KISS/HUG, openFeedChannel
 * found no stream manager and every Chill visit fell back to the 3.5 s poll.
 * This opens Chill, reports a process the way an external producer does, and
 * expects it on screen from a pushed frame well inside one poll interval.
 */
const EMAIL = process.env.CMS_E2E_EMAIL ?? 'owner@semitexa.test';
const PASSWORD = process.env.CMS_E2E_PASSWORD ?? 'walkthrough-2026';

async function enterShell(page: Page): Promise<void> {
    await page.goto('/os/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('form button[type="submit"], form input[type="submit"]').first().click(),
    ]);
    await page.goto('/os', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-act="awaken"]').click();
    // A returning user is offered their snapshot; a fresh one goes straight in.
    const resume = page.locator('[data-act="enter-continue"], [data-act="enter-fresh"]').first();
    await expect(resume.or(page.locator('.os-modebtn').first())).toBeVisible({ timeout: 10_000 });
    if (await resume.isVisible()) await resume.click();
}

function report(page: Page, body: object): Promise<number> {
    return page.evaluate(async (body) => {
        const token = decodeURIComponent((document.cookie.split(/;\s*/).find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').slice(11));
        const res = await fetch('/os/process/report', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify(body),
        });
        return res.status;
    }, body);
}

test('a reported process reaches Chill over the page stream, without a poll', async ({ page }) => {
    await page.addInitScript(() => {
        const Native = window.EventSource;
        const w = window as unknown as { __processFrames: number; __processIds: string[][] };
        w.__processFrames = 0;
        w.__processIds = [];
        window.EventSource = class extends Native {
            constructor(url: string | URL, init?: EventSourceInit) {
                super(url, init);
                this.addEventListener('ui.collection.data', (ev) => {
                    w.__processFrames++;
                    try {
                        const data = JSON.parse((ev as MessageEvent).data).data;
                        w.__processIds.push(Array.isArray(data) ? data.map((p: { id?: string }) => String(p.id ?? '')) : []);
                    } catch {
                        w.__processIds.push([]);
                    }
                });
            }
        } as typeof EventSource;
    });
    const polls: number[] = [];
    let listInFlight = 0;
    page.on('request', (request) => {
        if (request.url().includes('/os/process/list')) { polls.push(Date.now()); listInFlight++; }
    });
    for (const done of ['requestfinished', 'requestfailed'] as const) {
        page.on(done, (request) => { if (request.url().includes('/os/process/list')) listInFlight--; });
    }

    await enterShell(page);
    await expect(page.locator('meta[name="semitexa-ui-sse-session"]')).toHaveCount(1);
    await page.locator('.os-modebtn[data-mode="chill"]').click();
    // The first frame is the feed's initial paint: from here on the poll is off.
    await expect.poll(() => page.evaluate(() => (window as unknown as { __processFrames: number }).__processFrames), { timeout: 10_000 }).toBeGreaterThan(0);

    const id = 'e2e-proc-' + Date.now().toString(36);
    const title = 'E2E pushed process ' + id;
    // A list request still in flight could finish after the report and show
    // the process without any push: let it settle, then count from here.
    await expect.poll(() => listInFlight, { timeout: 5_000 }).toBe(0);
    const framesBefore = await page.evaluate(() => (window as unknown as { __processIds: string[][] }).__processIds.length);
    const sentAt = Date.now();
    try {
        expect(await report(page, { action: 'begin', id, source: 'e2e', title })).toBe(200);
        // The proof is a frame pushed AFTER the report that carries the process.
        await expect.poll(
            () => page.evaluate((from) => (window as unknown as { __processIds: string[][] }).__processIds.slice(from).flat(), framesBefore),
            { timeout: 3_000 },
        ).toContain(id);
        await expect(page.getByText(title)).toBeVisible();
        expect(polls.filter((at) => at >= sentAt), 'the list arrived without polling for it').toEqual([]);
    } finally {
        await report(page, { action: 'complete', id, source: 'e2e' }).catch(() => undefined);
    }
});
