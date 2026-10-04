import { test, expect, Page } from '@playwright/test';
import { php, wp } from '../support/cli';
import { DEV_URL } from '../support/env';
import {
	Site,
	batchPaused,
	clearKnobs,
	clearQueue,
	createSite,
	createUser,
	drainQueue,
	membersOf,
	memberships,
	queue,
	releaseBatch,
	setKnobs,
	setToggles,
	unique,
	uniqueLogin,
} from '../support/network';
import { networkScreen, submit, syncForm } from '../support/ui';

/**
 * The three automatic triggers, each fired the way a person fires it: a user
 * added in Network Admin or signing up on their own, a site added in Network
 * Admin, a role changed on a site's Users screen. Each is tested on and off.
 */

test.afterEach(() => {
	clearKnobs();
	clearQueue();
	setToggles({ newSite: false, newUser: false, role: false });
});

/** Network Admin › Users › Add New. Returns the new account's id. */
async function addUserInNetworkAdmin(page: Page, hint: string): Promise<{ id: number; login: string }> {
	const login = uniqueLogin(hint);

	await page.goto(`${DEV_URL}/wp-admin/network/user-new.php`);
	await page.locator('#username').fill(login);
	await page.locator('#email').fill(`${login}@example.com`);
	await Promise.all([page.waitForURL(/update=added/), page.locator('#add-user').click()]);

	return { id: Number(wp(['user', 'get', login, '--field=ID'])), login };
}

/** Network Admin › Sites › Add New. Returns the new site's id. */
async function addSiteInNetworkAdmin(page: Page, hint: string): Promise<{ id: number; slug: string }> {
	const slug = unique(hint);
	const email = wp(['user', 'get', 'admin', '--field=user_email']);

	await page.goto(`${DEV_URL}/wp-admin/network/site-new.php`);
	await page.locator('#site-address').fill(slug);
	await page.locator('#site-title').fill(slug);
	await page.locator('#admin-email').fill(email);
	await Promise.all([page.waitForURL(/site-new\.php\?id=\d+/), page.locator('#add-site').click()]);

	return { id: Number(new URL(page.url()).searchParams.get('id')), slug };
}

/** A site's Users screen: changes one person's role there with the bulk "Change role to…". */
async function changeRoleOnSite(page: Page, siteAdmin: string, userId: number, role: string): Promise<void> {
	await page.goto(`${siteAdmin}users.php`);
	await page.locator(`#user_${userId}`).check();
	await page.locator('#new_role').selectOption(role);
	await Promise.all([page.waitForURL(/update=promote/), page.locator('#changeit').click()]);
}

test.describe('New User Automatic Sync', () => {
	test('on: a user added in Network Admin joins every live site with each site\'s default role', async ({ page }) => {
		const editors = createSite('nu-editors', { defaultRole: 'editor' });
		const plain = createSite('nu-plain');
		const archived = createSite('nu-archived', { archived: true });
		const spam = createSite('nu-spam', { spam: true });
		setToggles({ newUser: true });

		const user = await addUserInNetworkAdmin(page, 'nu-on');
		const mine = memberships(user.id);

		expect(mine[editors.id]).toBe('editor');
		expect(mine[plain.id]).toBe('subscriber');
		expect(mine[archived.id], 'archived sites are skipped').toBeUndefined();
		expect(mine[spam.id], 'spam sites are skipped').toBeUndefined();
	});

	test('off: the same user joins no site', async ({ page }) => {
		const plain = createSite('nu-off-site');

		const user = await addUserInNetworkAdmin(page, 'nu-off');

		expect(memberships(user.id)[plain.id]).toBeUndefined();
	});

	test('on: a site excluded by wpmus_excluded_site_ids is skipped', async ({ page }) => {
		const excluded = createSite('nu-excluded');
		setKnobs({ excluded_site_ids: [excluded.id] });
		setToggles({ newUser: true });

		const user = await addUserInNetworkAdmin(page, 'nu-excl');

		expect(memberships(user.id)[excluded.id]).toBeUndefined();
	});

	test('on: an account made by another plugin with wp_insert_user() alone is synced too', async () => {
		const plain = createSite('nu-insert-site');
		setToggles({ newUser: true });

		const login = unique('nu-insert');
		const id = php<number>(`return (int) wp_insert_user( array( 'user_login' => '${login}', 'user_pass' => 'x-${login}', 'user_email' => '${login}@example.com' ) );`);

		expect(memberships(id)[plain.id]).toBe('subscriber');
	});
});

test.describe('New User Automatic Sync, for someone who signs up on their own', () => {
	// Nobody signed in: a visitor of one of the network's sites.
	test.use({ storageState: { cookies: [], origins: [] } });

	let registration: string;

	test.beforeEach(() => {
		registration = php<string>(`$was = (string) get_site_option( 'registration', 'none' ); update_site_option( 'registration', 'user' ); return $was;`);
	});

	test.afterEach(() => {
		php(`update_site_option( 'registration', ${JSON.stringify(registration)} ); return true;`);
	});

	/**
	 * Signs up from a site's own "Register" link (WordPress sends a visitor of
	 * any site to the network's sign-up form), then opens the activation link
	 * of the email. Returns the account the activation made.
	 */
	async function signUpFrom(page: Page, site: Site, hint: string): Promise<{ id: number; login: string }> {
		const login = uniqueLogin(hint);

		await page.goto(`${site.url}wp-login.php?action=register`);
		await expect(page).toHaveURL(/wp-signup\.php/);
		await page.locator('#user_name').fill(login);
		await page.locator('#user_email').fill(`${login}@example.com`);
		await page.locator('#setupform input[type="submit"]').click();
		await expect(page.locator('#signup-content')).toContainText(login);

		// The link of the activation email, read where WordPress keeps it.
		const key = php<string>(`global $wpdb; return (string) $wpdb->get_var( $wpdb->prepare( "SELECT activation_key FROM {$wpdb->signups} WHERE user_login = %s", '${login}' ) );`);
		expect(key, 'a pending sign-up waits for its activation').not.toBe('');
		await page.goto(`${DEV_URL}/wp-activate.php?key=${key}`);
		await expect(page.locator('body')).toContainText(login);

		return { id: Number(wp(['user', 'get', login, '--field=ID'])), login };
	}

	test('on: activating the account joins every live site with each site\'s default role, not just the one signed up from', async ({ page }) => {
		const from = createSite('su-from');
		const editors = createSite('su-editors', { defaultRole: 'editor' });
		const archived = createSite('su-archived', { archived: true });
		setToggles({ newUser: true });

		const user = await signUpFrom(page, from, 'su-on');
		const mine = memberships(user.id);

		expect(mine[from.id]).toBe('subscriber');
		expect(mine[editors.id]).toBe('editor');
		expect(mine[archived.id], 'archived sites are skipped').toBeUndefined();
	});

	test('off: the activated account joins no site of the network', async ({ page }) => {
		const from = createSite('su-off-from');
		const other = createSite('su-off-other');

		const user = await signUpFrom(page, from, 'su-off');
		const mine = memberships(user.id);

		expect(mine[from.id]).toBeUndefined();
		expect(mine[other.id]).toBeUndefined();
	});
});

test.describe('New Site Automatic Sync', () => {
	test('on: a site added in Network Admin gets every user with its default role, and no super admin', async ({ page }) => {
		const person = createUser('ns-person');
		const other = createUser('ns-other');
		const boss = createUser('ns-boss', {}, true);
		setToggles({ newSite: true });

		const site = await addSiteInNetworkAdmin(page, 'ns-on');

		expect(memberships(person.id)[site.id]).toBe('subscriber');
		expect(memberships(other.id)[site.id]).toBe('subscriber');
		expect(memberships(boss.id)[site.id], 'super admins are skipped').toBeUndefined();
		// The site's creator is made its administrator by WordPress, not copied down to subscriber.
		const admin = Number(wp(['user', 'get', 'admin', '--field=ID']));
		expect(memberships(admin)[site.id]).toBe('administrator');
	});

	test('on: the main site\'s editors do not become the new site\'s editors', async ({ page }) => {
		const editor = createUser('ns-editor', { 1: 'editor' });
		setToggles({ newSite: true });

		const site = await addSiteInNetworkAdmin(page, 'ns-roles');

		expect(memberships(editor.id)[site.id]).toBe('subscriber');
	});

	test('on: someone removed from the new site is left off it by later syncs', async ({ page }) => {
		const person = createUser('ns-removed');
		setToggles({ newSite: true });

		const site = await addSiteInNetworkAdmin(page, 'ns-removal');
		expect(memberships(person.id)[site.id]).toBe('subscriber');

		php(`remove_user_from_blog( ${person.id}, ${site.id} ); return true;`);
		await page.goto(networkScreen('wpmus-networksyncactions'));
		await submit(page, syncForm(page, 'wpmusSyncNetworkFromScratch'));

		expect(memberships(person.id)[site.id]).toBeUndefined();
	});

	test('off: a new site gets only its administrator', async ({ page }) => {
		createUser('ns-off-person');

		const site = await addSiteInNetworkAdmin(page, 'ns-off');

		expect(membersOf(site.id)).toHaveLength(1);
	});

	test('on, on a big network: the new site is filled in the background, through WP-Cron', async ({ page }) => {
		const people = [createUser('ns-queue-a'), createUser('ns-queue-b')];
		setKnobs({ inline_limit: 1, batch_size: 1, time_limit: 0, hold_cron: true });
		setToggles({ newSite: true });

		const site = await addSiteInNetworkAdmin(page, 'ns-queue');

		expect(queue().map((job) => job.context)).toContain('new_site');
		expect(memberships(people[0].id)[site.id]).toBeUndefined();

		await page.goto(networkScreen('wpmus-networksyncactions'));
		await expect(page.locator('#wpbody-content table.widefat tbody tr')).toHaveCount(1);

		drainQueue();
		for (const person of people) {
			expect(memberships(person.id)[site.id]).toBe('subscriber');
		}
	});

	test('on: a site added while a background sync is running still gets filled', async ({ page }) => {
		test.setTimeout(180_000);
		const first = createSite('ns-busy-first');
		const person = createUser('ns-busy');
		setKnobs({ inline_limit: 1, batch_size: 1, time_limit: 0, pause_first_batch: 90 });
		php(`switch_to_blog( get_main_site_id() ); delete_transient( 'doing_cron' ); restore_current_blog(); return true;`);

		await page.goto(networkScreen('wpmus-networksyncactions'));
		const form = syncForm(page, 'wpmusSyncNetworkSiteFromScratch');
		await form.locator(`input[name="listSites[]"][value="${first.id}"]`).check();
		await submit(page, form);

		// A visit spawns WP-Cron, whose first batch holds the queue it read.
		await expect
			.poll(
				async () => {
					await page.request.get('/');
					return batchPaused();
				},
				{ timeout: 60_000, intervals: [1_000] }
			)
			.toBe(true);

		setToggles({ newSite: true });
		const site = await addSiteInNetworkAdmin(page, 'ns-busy');
		expect(queue().map((job) => job.context)).toContain('new_site');
		releaseBatch();

		// The paused run stores its batch and lets go of the lock; the new
		// site's job has to be in the queue it leaves.
		await expect.poll(() => php<boolean>(`return false !== get_site_option( 'wpmus_sync_lock' );`), { timeout: 60_000 }).toBe(false);
		expect(queue().map((job) => job.context)).toContain('new_site');

		drainQueue(500);
		expect(memberships(person.id)[first.id]).toBe('subscriber');
		expect(memberships(person.id)[site.id], 'the new site\'s sync, queued during the batch, ran too').toBe('subscriber');
	});
});

test.describe('Set User Role Automatic Sync', () => {
	test('on: a role changed on one site is copied to the user\'s other sites, and no new membership is made', async ({ page }) => {
		const a = createSite('role-a');
		const b = createSite('role-b');
		const c = createSite('role-c');
		const person = createUser('role-person', { [a.id]: 'subscriber', [b.id]: 'subscriber' });
		setToggles({ role: true });

		await changeRoleOnSite(page, a.admin, person.id, 'editor');

		const mine = memberships(person.id);
		expect(mine[a.id]).toBe('editor');
		expect(mine[b.id], 'copied to the other site').toBe('editor');
		expect(mine[c.id], 'never a new membership').toBeUndefined();
	});

	test('on: administrator is not copied, unless the wpmus_replicate_role filter allows it', async ({ page }) => {
		const a = createSite('admin-a');
		const b = createSite('admin-b');
		const person = createUser('admin-person', { [a.id]: 'subscriber', [b.id]: 'author' });
		setToggles({ role: true });

		await changeRoleOnSite(page, a.admin, person.id, 'administrator');
		expect(memberships(person.id)[b.id]).toBe('author');

		setKnobs({ replicate_administrator: true });
		await changeRoleOnSite(page, a.admin, person.id, 'editor');
		await changeRoleOnSite(page, a.admin, person.id, 'administrator');
		expect(memberships(person.id)[b.id]).toBe('administrator');
	});

	test('on: a site that does not define the role keeps the user\'s role there', async ({ page }) => {
		const a = createSite('norole-a');
		const b = createSite('norole-b');
		const person = createUser('norole-person', { [a.id]: 'subscriber', [b.id]: 'subscriber' });
		wp(['role', 'delete', 'editor'], { url: b.url });
		setToggles({ role: true });

		await changeRoleOnSite(page, a.admin, person.id, 'editor');

		expect(memberships(person.id)[b.id]).toBe('subscriber');
	});

	test('on: a super admin\'s role is left alone', async ({ page }) => {
		const a = createSite('super-a');
		const b = createSite('super-b');
		const boss = createUser('super-boss', { [a.id]: 'subscriber', [b.id]: 'subscriber' }, true);
		setToggles({ role: true });

		await changeRoleOnSite(page, a.admin, boss.id, 'editor');

		expect(memberships(boss.id)[b.id]).toBe('subscriber');
	});

	test('off: a role change stays on its site', async ({ page }) => {
		const a = createSite('roleoff-a');
		const b = createSite('roleoff-b');
		const person = createUser('roleoff-person', { [a.id]: 'subscriber', [b.id]: 'subscriber' });

		await changeRoleOnSite(page, a.admin, person.id, 'editor');

		expect(memberships(person.id)[b.id]).toBe('subscriber');
	});
});
