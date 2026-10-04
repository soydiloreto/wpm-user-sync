# What the tests cover

Every feature and state of the plugin and the test that walks it, at each
layer: **unit** (`tests/Unit`, no WordPress), **integration** (`tests/Integration`,
a real network in wp-env) and **end-to-end** (`tests/e2e`, a browser on the dev
network; `single/` on a single site). A new feature or state adds its row in
the same pull request. How many of the plugin's lines each layer runs is
`make coverage` ([testing-and-quality.md](../../docs/testing-and-quality.md#coverage)).

## Triggers

| Feature / state | End-to-end | Integration | Unit |
|---|---|---|---|
| New user, on: joins every live site with each site's default role | `triggers` › New User › on: a user added in Network Admin… | `SyncEngineIntegrationTest`, `HooksIntegrationTest` | `SyncEngineTest` |
| New user, off: joins no site | `triggers` › New User › off | `SyncEngineIntegrationTest` | `SyncEngineTest` |
| New user made with `wp_insert_user()` alone (another plugin), once per account | `triggers` › …with wp_insert_user() alone | `HooksIntegrationTest` | `SyncEngineTest` |
| Self-registration: a visitor signs up from a site's "Register" link and activates from the email; on, every live site; off, none | `triggers` › …for someone who signs up on their own (on and off) | `TriggerStatesIntegrationTest` | `SyncEngineTest` |
| Invitee activated from a signup is synced again | — | `TriggerStatesIntegrationTest` | `SyncEngineTest` |
| New site, on: every user with the new site's default role | `triggers` › New Site › on | `NewSiteRoleIntegrationTest` | `SyncEngineTest` |
| New site never copies main-site roles | `triggers` › …the main site's editors… | `NewSiteRoleIntegrationTest` | `SyncEngineTest` |
| New site, off: only its administrator | `triggers` › New Site › off | `TriggerStatesIntegrationTest` | `SyncEngineTest` |
| Role change, on: copied to the user's other sites, no new membership | `triggers` › Set User Role › on | `RoleReplicationIntegrationTest` | `SyncEngineTest` |
| Role change: administrator not copied; allowed by `wpmus_replicate_role` | `triggers` › …administrator is not copied… | `RoleReplicationIntegrationTest` | `SyncEngineTest` |
| Role change: a site without the role keeps the user's role | `triggers` › …does not define the role… | `RoleReplicationIntegrationTest` | `SyncEngineTest` |
| Role change: super admins left alone; no-op changes ignored | `triggers` › …a super admin's role… | `TriggerStatesIntegrationTest` | `SyncEngineTest` |
| Role change, off | `triggers` › Set User Role › off | `TriggerStatesIntegrationTest` | `SyncEngineTest` |
| No cascade: a sync's own role writes do not re-trigger the role sync | — | `ReentrancyGuardIntegrationTest` | `SyncEngineTest` |
| Toggle takes effect immediately (hooks always registered, toggle read when they run) | the `triggers` tests turn toggles on mid-run | `HooksIntegrationTest`, `PluginBootstrapTest` | `ConfigTest` |

## Who is synced where

| Feature / state | End-to-end | Integration | Unit |
|---|---|---|---|
| Super admins never added as members | `network-admin` › Sync from scratch…, `triggers` › New Site › on | `ExclusionsIntegrationTest` | `SyncEngineTest`, `UserRepositoryTest` |
| Archived and spam sites skipped (deleted and other networks' too) | `network-admin` › Sync from scratch…, `triggers` › New User › on | `ExclusionsIntegrationTest` | `SiteRepositoryTest` |
| `wpmus_excluded_site_ids` | `network-admin` › …excluded by…, `triggers` › …excluded… | `ExclusionsIntegrationTest` | `SyncEngineTest` |
| `wpmus_should_sync_user` | — | `ExclusionsIntegrationTest` | `SyncEngineTest` |
| Removal recorded when an administrator removes someone | `network-admin` › Removals › …stays off it… | `RemovalRespectedIntegrationTest` | `UserRepositoryTest` |
| Removed people stay off: triggers and plain manual syncs | `network-admin` › Removals, `triggers` › …removed from the new site… | `RemovalRespectedIntegrationTest` | `SyncEngineTest` |
| "Also add back people who were removed" on both manual forms | `network-admin` › Removals (both tests) | `RemovalRespectedIntegrationTest`, `NetworkSyncActionsIntegrationTest` | `SyncEngineTest` |
| Adding someone back by hand clears the record; core housekeeping is not a removal | — | `RemovalRespectedIntegrationTest` | `SyncEngineTest` |

## Network Admin

| Feature / state | End-to-end | Integration | Unit |
|---|---|---|---|
| Menu: home, options, actions; home tabs; unknown tab falls back | `network-admin` › The network menu | `AdminMenusIntegrationTest` | `NetworkMenuTest`, `NetworkHomePageTest` |
| Options: each toggle saves on and off and reads back; notice | `network-admin` › Network Sync Options | `ConfigPersistenceTest`, `AdminScreensIntegrationTest` | `ConfigTest` |
| Options: a save without the nonce is refused | `network-admin` › …without the form nonce… | `AdminHandlersIntegrationTest` | `NetworkSyncOptionsPageTest` |
| Sync from scratch: every user, every live site, each default role | `network-admin` › "Sync from scratch"… | `NetworkSyncActionsIntegrationTest`, `SyncEngineIntegrationTest` | `SyncEngineTest` |
| Sync specific sites: only the ticked ones; none ticked warns | `network-admin` › "Sync specific sites"… (two tests) | `NetworkSyncActionsIntegrationTest`, `AdminScreensIntegrationTest` | — |
| The site list offers live sites only | `network-admin` › the site list… | `AdminScreensIntegrationTest` | `SiteRepositoryTest` |
| Actions refused without the nonce | `network-admin` › the actions are refused… | `AdminHandlersIntegrationTest` | `NetworkSyncActionsPageTest` |
| Options and network syncs refused (403) to anyone without the network capability, even with a valid nonce | — (Network Admin itself turns them away first: `permissions` › …cannot reach Network Admin's screens) | `AdminHandlersIntegrationTest` | `NetworkSyncOptionsPageTest`, `NetworkSyncActionsPageTest` |
| Notices only on the plugin's screens | `network-admin` (every save and sync checks its notice) | `AdminScreensIntegrationTest`, `AdminMenusIntegrationTest` | `NoticesTest` |

## Background syncs

| Feature / state | End-to-end | Integration | Unit |
|---|---|---|---|
| Small sync finishes in the request | every manual-sync test | `QueueIntegrationTest` | `SyncEngineTest` |
| Big manual sync queued, notice, progress table, WP-Cron event scheduled | `network-admin` › a sync too big for one request… | `QueueIntegrationTest`, `AdminScreensIntegrationTest` | `SyncEngineTest`, `SyncJobTest` |
| One cron run of one batch moves the progress; the queue drains; nothing left scheduled | `network-admin` › a sync too big… | `QueueIntegrationTest` | `SyncEngineTest` |
| Real WP-Cron, spawned by visits, finishes a queued sync | `network-admin` › a visit to the site is enough… | — | — |
| New-site trigger on a big network runs through the queue | `triggers` › …filled in the background… | `QueueIntegrationTest` | `SyncEngineTest` |
| A run that finds the queue locked leaves a retry for when the lock goes stale; a stale lock is taken over | `network-admin` › a run that died holding the lock… | `QueueIntegrationTest`, `PluginWiringIntegrationTest` | `JobQueueTest`, `SyncEngineTest` |
| A job queued while a batch runs is kept; a queue emptied meanwhile stays empty | `triggers` › a site added while a background sync is running… | `QueueIntegrationTest` | `JobQueueTest` |
| A cron run commits its writes in groups of about a second (only there), reading at READ COMMITTED; a group refused, or rolled back behind a successful COMMIT (checked in the database), leaves the batch to a run a minute later with its users read afresh; one by one where the database logs statements; `wpmus_sync_group_writes` turns it off | every background-sync test (grouping is on by default) | `WriteGroupsIntegrationTest` | `WriteGroupsTest`, `SyncEngineTest`, `UserRepositoryTest` |
| A big network with the real limits: fast start, each run bounded in time and memory, every membership once (`make test-load`) | — | `NetworkLoadTest` (`tests/Load`) | — |
| `wpmus_sync_inline_limit`, `wpmus_sync_batch_size`, `wpmus_sync_time_limit` | the queued tests above (through the e2e knobs) | `QueueIntegrationTest` | `SyncEngineTest` |

## A site's dashboard and permissions

| Feature / state | End-to-end | Integration | Unit |
|---|---|---|---|
| Super admin: site menu, home tabs | `permissions` › …sees the site menu… | `AdminMenusIntegrationTest` | `SiteMenuTest`, `SiteHomePageTest` |
| Site "Sync from scratch": that site only, its default role | `permissions` › "Sync from scratch" fills that site… | `SiteSyncCapabilityIntegrationTest` | `SiteSyncActionsPageTest` |
| Site administrator: no menu, site screens refused | `permissions` › …has no plugin menu… | `SiteSyncCapabilityIntegrationTest`, `AdminMenusIntegrationTest` | `SiteMenuTest` |
| Site administrator: Network Admin screens unreachable | `permissions` › …cannot reach Network Admin's screens | `AdminMenusIntegrationTest` | `NetworkMenuTest` |
| Site administrator: the action refused even when posted directly | `permissions` › …cannot run the site sync… | `SiteSyncCapabilityIntegrationTest` | `SiteSyncActionsPageTest` |

## Lifecycle

| Feature / state | End-to-end | Integration | Unit |
|---|---|---|---|
| Single site: activating explains and deactivates | `single/single-site` › activating it explains… | — | `RequirementsCheckerTest` |
| Single site: nothing hooked, nothing stored, no menu | `single/single-site` › while it was active it hooked nothing… | — | `PluginTest` |
| WordPress older than Requires at least: deactivates and names the version | — | `PluginWiringIntegrationTest` | `RequirementsCheckerTest` |
| Network: every trigger, menu and the queue hooked | — | `PluginBootstrapTest`, `PluginWiringIntegrationTest` | `PluginTest` |
| Translations left to WordPress (no `load_plugin_textdomain`) | — | `PluginBootstrapTest` | `PluginTest` |
| Stylesheet only on the plugin's screens, versioned by `WPMUS_VERSION` | — | `AdminMenusIntegrationTest` | `AssetsTest` |
| Uninstall: settings, queue, cron event, removal record gone; memberships kept | `uninstall` | `UninstallIntegrationTest` | `UninstallTest` |
| Deprecated 1.4 function names still dispatch | `legacy-functions` | `TriggerStatesIntegrationTest`, `PluginWiringIntegrationTest` | `LegacyDeprecatedTest` |
| Option names unchanged since 1.4 | — | `ConfigPersistenceTest` | `SmokeTest` |

## How it looks

| Feature / state | Covered by |
|---|---|
| Every screen and tab listed; nothing missing from the registry | `admin-layout` › The registry is complete |
| Layout invariants of every screen at 1600/1280/960/782 px | `admin-layout` › Every screen holds its layout |
| …with a background sync listed, and after a save | `admin-layout` › The states a screen can be in… |
| Pictures of every screen against the baselines (`make test-visual`) | `admin-snapshots` |
| The wordpress.org listing screenshots (`make screenshots`) | `listing-screenshots` |
