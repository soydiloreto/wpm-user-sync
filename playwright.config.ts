import { defineConfig, devices } from '@playwright/test';
import { ADMIN_STATE, DEV_URL, SINGLE_URL } from './tests/e2e/support/env';

/**
 * End-to-end tests against the wp-env DEV site, which is a subdirectory
 * network (.wp-env.json, "multisite": true, port 8898): Network Admin, a
 * site's dashboard and every trigger, in a real browser.
 *
 * The dev site mounts the repository, so what runs here is the working tree.
 * tests/e2e/mu-plugin/ is mapped as mu-plugins: wpmus-e2e.php lets a test
 * turn the plugin's public filters from a network option; WP-CLI (through
 * `npx wp-env run cli`) makes the users and sites a test needs and reads what
 * the plugin did.
 *
 * `npx playwright test` is what the shared CI runs: the setup, the network
 * specs and the teardown. Three projects exist only when asked for:
 *
 *   single   WPMUS_SINGLE_URL=…   the single-site check (`make test-e2e-single`)
 *   visual   WPMUS_SNAPSHOTS=1    the pictures (`make test-visual`)
 *   listing  WPMUS_LISTING=1      the wordpress.org screenshots (`make screenshots`)
 *
 * Override WP_BASE_URL / WP_USER / WP_PASS to point the suite elsewhere.
 */

/**
 * A baseline image is a picture of one machine's font rendering, so the
 * picture suite is opt-in (`make test-visual`) rather than part of what CI
 * compares; the measurements in admin-layout.spec.ts mean the same everywhere
 * and run with everything else.
 */
const PICTURES = process.env.WPMUS_SNAPSHOTS === '1';

/** Writes .wordpress-org/, the listing itself: never a side effect of a test run. */
const LISTING = process.env.WPMUS_LISTING === '1';

/** Pinned for pictures: a picture taken in another window is of another screen. */
const STILL = {
	...devices['Desktop Chrome'],
	deviceScaleFactor: 1,
	reducedMotion: 'reduce' as const,
};

export default defineConfig({
	testDir: './tests/e2e/specs',
	timeout: 120_000,
	expect: {
		timeout: 10_000,
		toHaveScreenshot: {
			animations: 'disabled',
			caret: 'hide',
			scale: 'css',
			maxDiffPixelRatio: 0.002,
		},
	},
	// One network, one sitemeta table: two tests at once would fight over the
	// same toggles and the same queue.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : [['list']],
	outputDir: 'build/e2e-results',
	snapshotPathTemplate: 'tests/e2e/snapshots/{arg}-{platform}{ext}',
	use: {
		baseURL: DEV_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'setup',
			testDir: './tests/e2e',
			testMatch: /global\.setup\.ts/,
			teardown: 'teardown',
		},
		{
			name: 'teardown',
			testDir: './tests/e2e',
			testMatch: /global\.teardown\.ts/,
		},
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'], storageState: ADMIN_STATE },
			testIgnore: /(admin-snapshots|listing-screenshots)\.spec\.ts/,
			dependencies: ['setup'],
		},
		...(SINGLE_URL
			? [
					{
						name: 'single',
						testDir: './tests/e2e/single',
						use: { ...devices['Desktop Chrome'], baseURL: SINGLE_URL },
					},
				]
			: []),
		...(PICTURES
			? [
					{
						name: 'visual',
						testMatch: /admin-snapshots\.spec\.ts/,
						use: { ...STILL, viewport: { width: 1280, height: 900 }, storageState: ADMIN_STATE },
						dependencies: ['setup'],
					},
				]
			: []),
		...(LISTING
			? [
					{
						name: 'listing',
						testMatch: /listing-screenshots\.spec\.ts/,
						use: { ...STILL, viewport: { width: 1280, height: 1000 }, storageState: ADMIN_STATE },
						dependencies: ['setup'],
					},
				]
			: []),
	],
});
