# Architecture

How DiluxOne Multisite User Sync (slug `wpm-user-sync`) works, the rules it
never breaks, and what a review of a change looks for first. The Claude
review reads this page on every pull request.

## What it does

A WordPress network keeps one list of accounts and many sites; membership (a
user having a role on a site) is what joins them. Core adds nobody anywhere
on its own. The plugin adds memberships, never accounts:

| Entry point | Fired by | Does |
| --- | --- | --- |
| New user trigger | `wpmu_new_user`, `user_register` (+ `shutdown` for accounts made with `wp_insert_user()` alone), `wpmu_activate_user` | The new user joins every live site, with each site's default role. Once per account per request. |
| New site trigger | `wp_initialize_site` (priority 11, after core populated the site) | Every user joins the new site, with the new site's default role. |
| Role trigger | `set_user_role` | The new role is copied to the other sites the user already belongs to, where the role exists. Never creates a membership. |
| Network "Sync from scratch" | Network Admin › User Sync › Network Sync Actions | Every user to every live site, or only to the ticked ones. |
| Site "Sync from scratch" | A site's dashboard › User Sync › Site Sync Actions (super admins) | Every user to that one site. |
| Removal record | `remove_user_from_blog`, `add_user_to_blog` | Remembers who an administrator removed from which site; adding them back by hand forgets it. Runs whatever the toggles say. |
| Queue | `wpmus_process_sync_queue` (WP-Cron, main site) | Works through the syncs too big for one request, a batch at a time. |

Each trigger reads its toggle (a network option) when it fires, so turning
one on or off takes effect at once. The three toggles are the only settings.

## Code map

| Path | Role |
| --- | --- |
| `wpm-user-sync.php` | Headers, `WPMUS_VERSION`, the autoloader, `new Plugin( __FILE__ )->register()`. |
| `src/Plugin.php` | Builds the object graph and hooks everything. Outside a network it hooks the requirements check only. |
| `src/RequirementsChecker.php` | On `admin_init`: not a network, or WordPress older than *Requires at least* → deactivate and explain (`wp_die`). |
| `src/Config.php` | The three toggles (`wpmus_newSiteSync`, `wpmus_newUserSync`, `wpmus_setUserRoleSync`, stored `'yes'` or `''`) and plugin metadata. |
| `src/Sync/SyncEngine.php` | Every membership write. Triggers and actions become a `SyncJob`; small ones run at once, big ones are queued. |
| `src/Sync/WriteGroups.php` | Commits a cron run's writes in transactions of about a second. |
| `src/Sync/SyncJob.php`, `src/Sync/JobQueue.php` | A job's scope, cursor and progress; the queue in the network option `wpmus_sync_jobs`, its lock `wpmus_sync_lock`, and its cron event on the main site. |
| `src/Repositories/SiteRepository.php` | The live sites of this network (not archived, spam or deleted), a site's default role (subscriber when the role does not exist there), whether a role exists on a site. |
| `src/Repositories/UserRepository.php` | Network users a page of ids at a time, super admins, memberships, the removal record (user meta `wpmus_removed_from_blogs`). |
| `src/Admin/*` | The network menu (home, options, actions) and the site menu (home, actions), their forms and save handlers. |
| `src/Notices.php`, `src/View/Header.php`, `src/Assets.php` | The notices after a redirect (only on `wpmus-*` screens), the shared header, the stylesheet (only on the plugin's screens). |
| `legacy-deprecated.php` | The 1.4 function names, deprecated since 1.5.0, dispatching to the engine. |
| `uninstall.php` | Deletes the toggles, the queue, its lock and cron event, and the removal record. Never touches users or memberships. |

`src/` is PSR-4 under `WPMUS\`, loaded by `src/Autoloader.php`; the plugin has
no Composer dependencies at runtime.

## How a sync runs

1. The entry point builds a `SyncJob`: a context (`new_site`, `new_user`,
   `manual`), the users (one, or all) and the sites (one, some, or all).
2. The target sites are the live sites of this network, intersected with the
   requested ones, minus `wpmus_excluded_site_ids`.
3. Its size is sites × users. Up to `wpmus_sync_inline_limit` (500) pairs it
   runs in the request; above, it is queued, a single WP-Cron event is
   scheduled on the main site, and the screen says so.
4. Each pair: skip a super admin, an existing member, someone removed from
   that site (unless the job is forced), and whatever `wpmus_should_sync_user`
   refuses; otherwise add with the site's default role.
5. A cron run takes the lock, processes batches of `wpmus_sync_batch_size`
   (500) pairs for up to `wpmus_sync_time_limit` (20) seconds, stores the
   cursor after each batch, and schedules the next run while jobs remain. A
   run that finds the lock taken schedules a retry for when that lock goes
   stale (ten minutes), so a run that died never leaves the queue stuck; a
   run that ends first brings the next one forward, and a run that empties
   the queue removes it.
   A cron run commits its writes in transactions of about a second
   (`WriteGroups`, off with `wpmus_sync_group_writes`); only there, because
   that request is the plugin's own and nobody else's transaction can be
   open in it. Each group reads at READ COMMITTED, so it never misses a
   membership another request just added; a database logging statements for
   replication, which refuses that, writes one by one. A COMMIT that goes
   through does not prove a group survived (a deadlock or a reopened
   connection rolls it back unseen), so before storing a batch's progress the
   run counts, in the database itself, the memberships the batch wrote. When
   any is missing, or a COMMIT is refused (and then rolled back on purpose),
   the run stores nothing, drops those users from the object cache and
   retries a minute later, or at the run already pending when there is one.
   After every commit the group's users are dropped from the object cache
   again: core cleans it before the commit, and a request reading them
   meanwhile may have cached what was there before. A run that dies drops at
   most its last group. Either way those memberships are added again, never
   skipped.
   Users are read a page of ids at a time; sites are walked in id order, so a
   run that dies loses at most one batch, which the next redoes harmlessly.
   Every change to the queue rereads it from the database first, past the
   request's cache, so a job queued or a queue emptied by another request
   while a batch runs is not written over. Only the moment between that read
   and the write is left open: option updates are not atomic.

The role trigger does not queue: it touches only the sites the user already
belongs to. It returns at once when the role did not change, for super
admins, and for `administrator` unless `wpmus_replicate_role` allows it.

**Re-entrancy.** `add_user_to_blog()` fires `set_user_role`. Every write the
engine makes goes through `add_to_blog_guarded()`, which sets an `$in_sync`
flag that the role trigger honours, so a sync never cascades into the role
trigger.

## Hard rules

A change that breaks one of these is a blocker, whatever else it fixes.

1. **Memberships only.** No sync creates, duplicates or deletes an account.
2. **Existing memberships are not changed**, except by the role trigger, and
   that one only copies a role that exists on the destination, never
   `administrator` by default, never for a super admin.
3. **A new membership gets the destination site's default role.** Never the
   role the user holds elsewhere: that made main-site administrators
   administrators of every new site.
4. **Nothing is written to** an archived, spam or deleted site, another
   network's site, or an excluded one; **super admins are never added**.
5. **A removal stands.** Someone an administrator removed from a site stays
   off it for every trigger and every plain manual sync; only a person
   ticking "Also add back people who were removed from a site" overrides it.
   Core housekeeping (deleting a site, activating an invitee) is not a
   removal.
6. **Every screen and handler checks capability and nonce first.** Network
   options: `manage_network_options`. Syncs, network or site:
   `manage_network_users`. A site administrator never sees the plugin.
7. **Bounded work per request.** No query loads every user; big jobs go to
   the queue; one cron run holds the lock and a time budget.
8. **Published names are permanent.** The slug and text domain
   `wpm-user-sync`, the option and meta keys, the page slugs (`wpmus-*`), the
   admin-post actions, the hooks and filters, the 1.4 function names and
   the global `$sd_active_tab` stay. A stored key changes only with a
   migration.
9. **Outside a network, nothing but the requirements check runs.**
10. **Uninstall removes the plugin's data and nothing else.**
11. **WordPress conventions**: every string translatable with the
    `wpm-user-sync` domain and a translators comment on placeholders; input
    unslashed and sanitized; output escaped at the point of output; every
    `switch_to_blog()` paired with `restore_current_blog()` (in a `finally`
    when the code between can throw); PHP 8.0 and WordPress 6.6 minimums.

## What the review looks for first

1. A path that writes a membership without the engine's guards (super admin,
   removal, live site, default role, `$in_sync`).
2. A missing or weaker capability or nonce check, or a check after work
   started.
3. Work that grows with the size of the network inside one request.
4. A renamed option, hook, page slug or legacy function.
5. Unescaped output, unsanitized input, a string without the text domain.
6. Tests: the change covered at every layer it touches (unit, integration on
   a network, end-to-end), and [`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md)
   updated.
