import { test, expect } from '@playwright/test';
import {
	TOGGLES,
	Toggle,
	clearKnobs,
	clearQueue,
	createSite,
	createUser,
	cronScheduled,
	drainQueue,
	memberships,
	queue,
	runCron,
	setKnobs,
	setToggles,
	toggles,
} from '../support/network';
import { notice, networkScreen, submit, syncForm } from '../support/ui';
import { php } from '../support/cli';

/**
 * Network Admin: the three screens a super admin has, the toggles and the two
 * manual syncs, and the progress of a sync too big for one request.
 */

test.afterEach(() => {
	clearKnobs();
	clearQueue();
	setToggles({ newSite: false, newUser: false, role: false });
});

test.describe('The network menu', () => {
	test('has the home, the options and the actions, and the home its three tabs', async ({ page }) => {
		await page.goto(networkScreen('wpmus-networkhome'));

		const menu = page.locator('#toplevel_page_wpmus-networkhome');
		await expect(menu).toBeVisible();
		await expect(menu.locator('a[href*="page=wpmus-networksyncoptions"]')).toHaveCount(1);
		await expect(menu.locator('a[href*="page=wpmus-networksyncactions"]')).toHaveCount(1);

		for (const tab of ['welcome', 'concepts', 'about']) {
			await page.goto(networkScreen('wpmus-networkhome', tab));
			await expect(page.locator(`.nav-tab-active[href*="tab=${tab}"]`)).toBeVisible();
			await expect(page.locator('#wpbody-content h3').first()).toBeVisible();
		}
	});

	test('an unknown tab falls back to the welcome tab', async ({ page }) => {
		await page.goto(networkScreen('wpmus-networkhome', 'nope'));
		await expect(page.locator('.nav-tab-active[href*="tab=welcome"]')).toBeVisible();
	});
});

test.describe('Network Sync Options', () => {
	for (const toggle of Object.keys(TOGGLES) as Toggle[]) {
		test(`the ${toggle} toggle saves on, reads back, and saves off`, async ({ page }) => {
			const box = page.locator(`input[name="${TOGGLES[toggle]}"]`);

			await page.goto(networkScreen('wpmus-networksyncoptions'));
			await box.check();
			await submit(page, page.locator('form[action*="wpmusSaveGlobalConfig"]'));

			await expect(notice(page)).toHaveClass(/updated/);
			await expect(box).toBeChecked();
			expect(toggles()[toggle]).toBe(true);

			// The other two were saved as they were: off.
			for (const other of (Object.keys(TOGGLES) as Toggle[]).filter((one) => one !== toggle)) {
				expect(toggles()[other], `${other} stays off`).toBe(false);
			}

			await page.reload();
			await expect(box).toBeChecked();
			await box.uncheck();
			await submit(page, page.locator('form[action*="wpmusSaveGlobalConfig"]'));
			await expect(box).not.toBeChecked();
			expect(toggles()[toggle]).toBe(false);
		});
	}

	test('a save without the form nonce is refused and changes nothing', async ({ page }) => {
		const response = await page.request.post(`${networkScreen('wpmus-networksyncoptions').replace('admin.php?page=wpmus-networksyncoptions', 'edit.php?action=wpmusSaveGlobalConfig')}`, {
			form: { wpmus_newSiteSync: 'yes', wpmus_newUserSync: 'yes', wpmus_setUserRoleSync: 'yes' },
		});

		expect(response.status()).toBe(403);
		expect(toggles()).toEqual({ newSite: false, newUser: false, role: false });
	});
});

test.describe('Network Sync Actions', () => {
	test('"Sync from scratch" adds every user to every live site with each site\'s default role', async ({ page }) => {
		const authors = createSite('authors', { defaultRole: 'author' });
		const plain = createSite('plain');
		const archived = createSite('archived', { archived: true });
		const spam = createSite('spam', { spam: true });
		const person = createUser('person');
		const boss = createUser('boss', {}, true);

		await page.goto(networkScreen('wpmus-networksyncactions'));
		await submit(page, syncForm(page, 'wpmusSyncNetworkFromScratch'));

		await expect(notice(page)).toHaveClass(/updated/);

		const mine = memberships(person.id);
		expect(mine[authors.id], 'the site that makes authors').toBe('author');
		expect(mine[plain.id], 'a site with the stock default').toBe('subscriber');
		expect(mine[archived.id], 'an archived site is left alone').toBeUndefined();
		expect(mine[spam.id], 'a spam site is left alone').toBeUndefined();
		expect(memberships(boss.id), 'a super admin reaches every site already and is added nowhere').toEqual({});
	});

	test('"Sync specific sites" with no site ticked warns and adds nobody', async ({ page }) => {
		const site = createSite('untouched');
		const person = createUser('untouched-person');

		await page.goto(networkScreen('wpmus-networksyncactions'));
		await submit(page, syncForm(page, 'wpmusSyncNetworkSiteFromScratch'));

		await expect(notice(page)).toHaveClass(/notice-warning/);
		expect(memberships(person.id)[site.id]).toBeUndefined();
	});

	test('"Sync specific sites" fills the ticked sites and only those', async ({ page }) => {
		const picked = createSite('picked', { defaultRole: 'contributor' });
		const skipped = createSite('skipped');
		const person = createUser('picked-person');

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await expect(form.locator(`input[name="listSites[]"][value="${skipped.id}"]`)).toBeVisible();
		await form.locator(`input[name="listSites[]"][value="${picked.id}"]`).check();
		await submit(page, form);

		await expect(notice(page)).toHaveClass(/updated/);
		const mine = memberships(person.id);
		expect(mine[picked.id]).toBe('contributor');
		expect(mine[skipped.id]).toBeUndefined();
	});

	test('the site list offers live sites only', async ({ page }) => {
		const live = createSite('listed');
		const archived = createSite('unlisted', { archived: true });

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await expect(form.locator(`input[value="${live.id}"]`)).toHaveCount(1);
		await expect(form.locator(`input[value="${archived.id}"]`)).toHaveCount(0);
	});

	test('a site excluded by the wpmus_excluded_site_ids filter is never written to', async ({ page }) => {
		const excluded = createSite('excluded');
		const included = createSite('included');
		const person = createUser('excluded-person');
		setKnobs({ excluded_site_ids: [excluded.id] });

		await page.goto(networkScreen('wpmus-networksyncactions'));
		await submit(page, syncForm(page, 'wpmusSyncNetworkFromScratch'));

		const mine = memberships(person.id);
		expect(mine[included.id]).toBe('subscriber');
		expect(mine[excluded.id]).toBeUndefined();
	});

	test('a sync too big for one request is queued, shows its progress, and WP-Cron finishes it', async ({ page }) => {
		const site = createSite('queued');
		const people = [createUser('queued-a'), createUser('queued-b')];
		setKnobs({ inline_limit: 1, batch_size: 1, time_limit: 0, hold_cron: true });

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await form.locator(`input[name="listSites[]"][value="${site.id}"]`).check();
		await submit(page, form);

		// Queued, not done: the notice says so and nobody is on the site yet.
		await expect(notice(page)).toHaveClass(/notice-info/);
		for (const person of people) {
			expect(memberships(person.id)[site.id]).toBeUndefined();
		}
		expect(cronScheduled(), 'a WP-Cron event carries the job').toBe(true);

		const [job] = queue();
		expect(job.context).toBe('manual');
		expect(job.processed).toBe(0);

		const progress = page.locator('#wpbody-content table.widefat tbody tr');
		await expect(progress).toHaveCount(1);
		await expect(progress.first()).toContainText(`0% (0 of ${job.total}`);

		// One run of one batch moves it on, and the screen says how far.
		runCron();
		expect(queue()[0].processed).toBe(1);
		await page.reload();
		await expect(progress.first()).toContainText(`(1 of ${job.total}`);

		drainQueue();
		await page.reload();
		await expect(page.locator('#wpbody-content table.widefat')).toHaveCount(0);
		for (const person of people) {
			expect(memberships(person.id)[site.id]).toBe('subscriber');
		}
		expect(cronScheduled(), 'nothing left to schedule').toBe(false);
	});

	test('a visit to the site is enough for WP-Cron to finish a queued sync', async ({ page }) => {
		test.setTimeout(180_000);
		const site = createSite('by-visits');
		const person = createUser('by-visits');
		setKnobs({ inline_limit: 1, batch_size: 1 });

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await form.locator(`input[name="listSites[]"][value="${site.id}"]`).check();
		await submit(page, form);
		await expect(notice(page)).toHaveClass(/notice-info/);

		// Nobody runs anything by hand: the main site's visits spawn WP-Cron.
		// A spawn left by an earlier test holds WP-Cron's lock for up to a
		// minute, so it starts released.
		php(`switch_to_blog( get_main_site_id() ); delete_transient( 'doing_cron' ); restore_current_blog(); return true;`);
		await expect
			.poll(
				async () => {
					await page.request.get('/');
					return queue().length;
				},
				{ timeout: 120_000, intervals: [1_000, 2_000, 3_000] }
			)
			.toBe(0);
		expect(memberships(person.id)[site.id]).toBe('subscriber');
	});

	test('a run that died holding the lock blocks the queue only until the lock goes stale', async ({ page }) => {
		const site = createSite('stale-lock');
		const person = createUser('stale-lock');
		setKnobs({ inline_limit: 0, hold_cron: true });

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await form.locator(`input[name="listSites[]"][value="${site.id}"]`).check();
		await submit(page, form);
		expect(queue()).toHaveLength(1);

		// Another run holds the lock: this one leaves the job alone.
		php(`update_site_option( 'wpmus_sync_lock', time() ); return true;`);
		runCron();
		expect(queue()[0].processed).toBe(0);
		expect(memberships(person.id)).toEqual({});
		expect(cronScheduled(), 'a retry is left for when the lock goes stale').toBe(true);

		// That run died ten minutes ago: the next one takes the lock over and finishes the job.
		php(`update_site_option( 'wpmus_sync_lock', time() - 601 ); return true;`);
		drainQueue();
		expect(memberships(person.id)[site.id]).toBe('subscriber');
		expect(php(`return get_site_option( 'wpmus_sync_lock', 'released' );`)).toBe('released');
	});

	test('the actions are refused without the form nonce', async ({ page }) => {
		const site = createSite('forged');
		const person = createUser('forged-person');
		const edit = networkScreen('x').replace('admin.php?page=x', 'edit.php');

		const all = await page.request.post(`${edit}?action=wpmusSyncNetworkFromScratch`, { form: { wpmus_force: 'yes' } });
		const some = await page.request.post(`${edit}?action=wpmusSyncNetworkSiteFromScratch`, { form: { 'listSites[]': String(site.id) } });

		expect(all.status()).toBe(403);
		expect(some.status()).toBe(403);
		expect(memberships(person.id)).toEqual({});
	});
});

test.describe('Removals', () => {
	test('someone removed from a site stays off it, unless "add back" is ticked', async ({ page }) => {
		const site = createSite('removal');
		const person = createUser('removed', { [site.id]: 'subscriber' });

		// Removed the way an administrator removes someone: the site's Users screen.
		await page.goto(`${site.admin}users.php`);
		const row = page.locator(`#user-${person.id}`);
		await row.hover();
		await row.locator('.row-actions .remove a').click();
		await Promise.all([page.waitForLoadState('load'), page.locator('#submit').click()]);
		expect(memberships(person.id)[site.id]).toBeUndefined();

		await page.goto(networkScreen('wpmus-networksyncactions'));
		await submit(page, syncForm(page, 'wpmusSyncNetworkFromScratch'));
		expect(memberships(person.id)[site.id], 'a plain sync leaves the removal alone').toBeUndefined();

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkFromScratch');
		await form.locator('input[name="wpmus_force"]').check();
		await submit(page, form);
		expect(memberships(person.id)[site.id], '"add back" puts them back').toBe('subscriber');
	});

	test('the same box on "Sync specific sites" puts a removed person back on the ticked site', async ({ page }) => {
		const site = createSite('removal-some');
		const person = createUser('removed-some', { [site.id]: 'subscriber' });
		php(`remove_user_from_blog( ${person.id}, ${site.id} ); return true;`);

		await page.goto(networkScreen('wpmus-networksyncactions'));
		let form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await form.locator(`input[name="listSites[]"][value="${site.id}"]`).check();
		await submit(page, form);
		expect(memberships(person.id)[site.id]).toBeUndefined();

		form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await form.locator(`input[name="listSites[]"][value="${site.id}"]`).check();
		await form.locator('input[name="wpmus_force"]').check();
		await submit(page, form);
		expect(memberships(person.id)[site.id]).toBe('subscriber');
	});
});
