import { test, expect } from '@playwright/test';
import { php } from '../support/cli';
import { clearQueue, createSite, createUser, memberships, setToggles } from '../support/network';

/**
 * An add-on written for 1.4 called the plugin's global functions. They are
 * kept, deprecated, and still do their work on the running network; this is
 * what such an add-on sees, so it runs through WordPress as the add-on would,
 * not a browser.
 */

test.afterEach(() => {
	clearQueue();
	setToggles({ newSite: false, newUser: false, role: false });
});

/** Calls a 1.4 function inside WordPress and returns the deprecation notices it raised. */
function callLegacy(call: string): string[] {
	return php<string[]>(`
		$deprecated = array();
		add_filter( 'deprecated_function_trigger_error', '__return_false' );
		add_action( 'deprecated_function_run', function ( $name ) use ( &$deprecated ) { $deprecated[] = $name; } );
		${call};
		return $deprecated;
	`);
}

test('wpmus_sync_newuser() adds the user to every live site', async () => {
	const site = createSite('legacy-user');
	const person = createUser('legacy-user');
	setToggles({ newUser: true });

	expect(callLegacy(`wpmus_sync_newuser( ${person.id} )`)).toEqual(['wpmus_sync_newuser']);
	expect(memberships(person.id)[site.id]).toBe('subscriber');
});

test('wpmus_sync_newsite() fills the site with every user', async () => {
	const person = createUser('legacy-site');
	const site = createSite('legacy-site');
	expect(memberships(person.id)[site.id]).toBeUndefined();
	setToggles({ newSite: true });

	expect(callLegacy(`wpmus_sync_newsite( ${site.id} )`)).toEqual(['wpmus_sync_newsite']);
	expect(memberships(person.id)[site.id]).toBe('subscriber');
});

test('wpmus_sync_newrole() copies the role to the user\'s other sites', async () => {
	const first = createSite('legacy-role-a');
	const second = createSite('legacy-role-b');
	const person = createUser('legacy-role', { [first.id]: 'subscriber', [second.id]: 'subscriber' });
	setToggles({ role: true });

	expect(callLegacy(`wpmus_sync_newrole( ${person.id}, 'editor' )`)).toEqual(['wpmus_sync_newrole']);
	expect(memberships(person.id)).toEqual({ [first.id]: 'editor', [second.id]: 'editor' });
});

test('wpmus_maybesync_newuser() only says it is deprecated', async () => {
	const site = createSite('legacy-maybe');
	const person = createUser('legacy-maybe');
	setToggles({ newUser: true });

	expect(callLegacy(`wpmus_maybesync_newuser( '${person.login}' )`)).toEqual(['wpmus_maybesync_newuser']);
	expect(memberships(person.id)[site.id]).toBeUndefined();
});
