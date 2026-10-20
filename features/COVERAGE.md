# site-command Behat coverage

Suite for `easyengine/site-command` (the `ee site` command and the html site type). 18 features, 183 scenarios (215 with outlines expanded). Run it the CI way (`tools/run-behat.sh site-command`): the files are copied into a core checkout's `features/` and core's `vendor/bin/behat` runs from the core root as root.

## Tags

| Tag | Scenarios | Meaning |
|---|---|---|
| (none of the tags below) | 125 | Local only: `*.test` sites, no public DNS, no remote storage, no global restarts. This is the default safe run. |
| `@known-bug` | 28 | Exposes a bug from `issues.md` (ID in the comment above the scenario, `SC-*` for the ones found while writing this suite; the comment also names the open PR that fixes it, if any). Expected to fail until fixed. Exclude with `--tags='~@known-bug'`. |
| `@destructive` | 12 | Restarts the GLOBAL nginx-proxy (proxy cache) or runs `ssl-renew --all` (refuses to start when an enabled LE site that isn't the suite's exists). Every scenario leaves the proxy running. |
| `@remote` | 15 | Uses the REAL rclone remote (`rclone-path` in config.yml). Touches only `<rclone-path>/bh-site-command-*`: the rclone path is listed first and only listed suite directories are purged (per site before a scenario, all of them after the feature). |
| `@le` | 11 | Let's Encrypt issuance on `*.ee4-test.rt.gw`. Locally: real LE, at most 4 certificates per full run; names contain `{RUN_ID}`, so repeated runs never hit the duplicate-certificate limit. On CI all 11 run against Pebble (`behat/ci/pebble-setup.sh`: EE's hardcoded LE host is redirected to Pebble, `{LE_ISSUER}` comes from `EE_BEHAT_LE_ISSUER`); 8 pass, the 3 `@known-bug` ones fail as expected (SSL-2, SSL-8, SSL-9). |
| `@le-real` | 0 | Needs the public LE service itself (rate limits, the real chain); never runs on CI. None so far: every `@le` scenario works on Pebble. |
| `@wp` | 8 | Needs the `wp` site type (site-type-wp in the core checkout) and `ee` on PATH; slow. |

Default safe run: `--tags='~@le&&~@remote&&~@destructive&&~@known-bug&&~@wp'`.

## Environment rules

- Every site, alias, hosts entry and remote backup path contains `bh-site-command-`. `@AfterFeature` deletes only sites matching `(^|\.)bh-site-command-`, never other sites; once none is left it also removes suite-named leftovers of known bugs (proxy `certs/`, `acme-conf/certs|var/`, site dirs, volumes labelled with a suite name); `@remote` features also purge the listed `<rclone-path>/bh-site-command-*` directories. `@AfterScenario` removes hosts entries the scenario added, the scenario's `{TMP}` dir under `/var/tmp`, and `{BACKUP_DIR}/bh-site-command-*` leftovers.
- The file-writing, hosts, delete and rclone steps refuse names and paths without the prefix (or outside `{TMP}`).
- HTTP steps resolve the site name to 127.0.0.1 (whitelisted, so no HTTP auth, and no /etc/hosts entry needed); `through the public IP` goes through `hostname -I` instead; `verified` uses real DNS and full TLS verification (LE only). Redirects are never followed.
- Waits poll (`I wait up to N seconds for URL to return CODE`, `... until the command ... succeeds`); there are no fixed sleeps, except `I wait for the next second` between back-to-back backups of one site (backup ids have one-second resolution, BKP-5).
- Scenarios are independent: each one uses its own site or the idempotent `Given the html site "..." exists` / `exists, created with "..."`. The exception is `le.feature`, which shares three sites in file order to stay within the LE budget (each scenario still creates what it needs when run alone).
- Commands that can hang on a known bug (CACHE-1) run under `with a timeout of N seconds`.
- Commands that could prompt (le-mail) are guarded by `Given the EasyEngine config value "le-mail" is set`.
- The failing LE alias (SSL-2) uses a `*.invalid` name, so no validation ever reaches a host outside `*.ee4-test.rt.gw`.

## Estimated runtime (4 CPU test host)

html create about 15-20 s, delete 5-10 s, proxy-cache toggle 10-15 s (proxy restart), html backup/restore 15-40 s, WordPress create 1.5-2.5 min, LE issuance 30-60 s.

| Selection | Estimate |
|---|---|
| Default safe run | ~40 min |
| `@destructive` | ~7 min |
| `@remote` (html part) | ~7 min |
| `@wp` (clone/sync + WP backup/restore) | ~16 min |
| `@le` | ~6 min |
| Everything | ~75-80 min |

## Inventory and mapping

Legend: covered / partial / not covered (reason). Feature files are abbreviated: site, create, delete, list, info, en-dis (enable-disable), rr (restart-reload), alias (update-alias), ussl (update-ssl-php), pcache (proxy-cache), clean, share, ssl, redirect, labels, backup (backup-restore), clone (clone-sync), le.

### Routing and global behaviour (`Site_Command::__invoke`, hooks.php)

| Item | Status | Scenarios |
|---|---|---|
| `ee site` usage | covered | site: Check site command is present |
| `ee help site`, `ee help site create` | covered | site: Help lists every site subcommand; Help of site create |
| `ee help site --type=X` routing hook, unknown type error | covered | site: Help for an unregistered site type fails |
| `ee site cmd-dump` (hidden) | covered | site: cmd-dump prints the site command tree as JSON |
| `--type=X` on create, unregistered type error | covered | site: Creating a site of an unregistered type fails; create: Create an html site (`--type=html`) |
| Type auto-detection from the site name / from the cwd | covered | info: The site is detected from the working directory; site outlines (unknown site messages) |
| Wrong site name inside a site dir ("Did you mean") | covered | info: Another site name inside a site directory is rejected with a hint |
| Name normalisation: `http(s)://`, trailing slash, add `.test`, lower-case | covered | create: A site name without a dot...; The URL scheme and a trailing slash...; Upper-case site names...; info: The site name may be given as a URL |
| EE v3 compat flags (`--html`, `--wp`, `--le`, `--mysql`, ...) | partial | create: The EE v3 --html flag (`--wp`/`--php`/`--le` belong to site-type-* / LE budget) |
| Removed EE v3 flags error (`--w3tc`, ...) | covered (known bug) | site: Removed EE v3 cache flags give a clear error |
| Unknown parameter / too many positionals / missing required positional | covered | site: Unknown parameters are rejected; Extra positional arguments; Subcommands with a required site name print the usage |
| Missing site outside a site dir (auto_site_name) | covered | site: outline over info, update, enable, disable, restart, reload, clean, ssl-info, backup, restore; ssl-renew scenario |
| Unknown site (get_site_info) | covered | site: outlines over delete, refresh, ssl-verify, ssl, info, enable, disable, restart, update, share |
| Disabled site refused | covered | en-dis: outline over restart, reload, update, refresh, share, backup, restore |
| `site_cleanup` hooks (redis purge, share cleanup) | not covered | redis purge only fires for WP cache sites (site-type-wp suite); share cleanup needs a live ngrok share |
| Signal rollback (pcntl handlers) | not covered | dead code (MIG-9); needs killing ee mid-create |

### create (html)

| Item | Status | Scenarios |
|---|---|---|
| `<site-name>` happy path: files, .env, main.conf, user.conf, index.html, compose file, nginx container, 3 labelled volumes, network, hosts entry, DB row, HTTP 200 | covered | create: Create an html site; labels: both scenarios |
| Duplicate site | covered | create: Creating a site that already exists fails and keeps the site |
| `--public-dir` | covered | create: Create a site with a custom public directory |
| `--skip-status-check` | covered | create: Create a site without the status check |
| `--alias-domains` (DB, server_name, VIRTUAL_HOST, HTTP on alias) | covered | create: Create a site with alias domains |
| Alias/site conflicts on create | covered | create: A domain that is an alias of another site...; An alias domain that belongs to another site...; known bug: The owner of an alias is reported correctly... |
| `--ssl=self` | covered | ssl: Create a site with a self-signed certificate; Info of a self-signed site |
| `--ssl=custom` + `--ssl-key/--ssl-crt`, validation, rollback | covered | ssl: Create a site with a custom certificate; Custom SSL needs both...; Custom SSL files must exist |
| `--ssl=inherit` (parent checks, `--wildcard` refusal) | covered | ssl: A subdomain site inherits...; Inheriting needs an existing parent / a parent with SSL / cannot be combined with --wildcard |
| `--ssl=le`, `--ssl=le --wildcard` | covered (@le) | le: Create a site with a Let's Encrypt certificate; A wildcard certificate waits for the DNS challenge |
| `--ssl` with an invalid value on create | not covered | `--ssl` is a flag on create, so no option validation; would only fail inside init_ssl after the containers are up |
| www redirect written only when www resolves; https redirect | covered | redirect: all scenarios |
| Rollback on failure (catch_clean) leaves no site/dir | covered | ssl: custom/inherit failure scenarios (site and dir absent) |
| Site name validation (SEC-1) | covered (known bug) | create: Site names that are not valid hostnames are rejected... |
| Global admin-tools auth init on create | not covered | belongs to auth-command / admin-tools-command suites |
| macOS paths (dnsmasq, IS_DARWIN) | not covered | Linux test host |

### delete

| Item | Status | Scenarios |
|---|---|---|
| `--yes`: containers, volumes, network, site root, hosts entry, redirect conf, DB row, clean STDERR | covered | delete: Delete an html site and everything that belongs to it |
| Second delete / unknown site | covered | delete: same scenario; site: outline |
| Prompt answered n / y | covered | delete: Answering no...; Answering yes... |
| Disabled site | covered | delete: A disabled site can be deleted |
| SSL files removed (self, LE + revoke) | covered | delete: Deleting a self-signed site removes its certificate; le: Deleting a Let's Encrypt site... |
| Proxy cache config removed | covered (@destructive) | pcache: Deleting a proxy-cached site removes its proxy config |
| Alias domains freed | covered | delete: Deleting a site frees its alias domains |
| LE leftovers (SSL-5/SSL-9) | covered (known bug) | le: Deleting a Let's Encrypt site leaves no ACME state behind |
| DB/user cleanup for external DB sites, cron rows | not covered | only php/wp sites have DBs and crons (site-type-*/cron-command suites) |

### list / info

| Item | Status | Scenarios |
|---|---|---|
| list `--format=table/text/json/csv/yaml/count`, invalid format | covered | list: all |
| `--enabled`, `--disabled`, both | covered | list: List only enabled/disabled; Both filters |
| "No sites found!" | not covered | depends on the whole host having no (enabled/disabled) sites |
| info table (Site, Site Root, Alias Domains, SSL, SSL Wildcard) | covered | info: Show site info as a table; ssl: Info of a self-signed site; alias: Add alias domains |
| info `--format=json`, invalid format | covered | info: Show site info as JSON; An unsupported format is rejected |
| info on a disabled site, URL-style name, cwd detection | covered | info: remaining scenarios |

### enable / disable / refresh / restart / reload / clean

| Item | Status | Scenarios |
|---|---|---|
| disable (containers removed, volumes kept, DB, proxy stops serving), idempotent | covered | en-dis: Disable a site; Disabling a disabled site is harmless |
| enable, already enabled, `--force`, `--refresh`, `--verify` | covered | en-dis: Enable...; Enabling an enabled site fails; Force-enable...; Enable with --refresh...; Enable with verification... |
| `refresh` subcommand | covered | en-dis: refresh is a shortcut for enable --refresh |
| enable/disable manage the www redirect | covered | redirect: Disable removes the redirect and enable restores it; known bug MIG-15 |
| enable post-configuration of postfix / admin-tools / mailhog | not covered | html sites have none (site-type-*, admin-tools, mailhog suites) |
| restart (no flag, `--nginx`, `--all`), unknown service flag | covered | rr: Restart the site containers (outline); Restart rejects services... |
| restart refuses a broken nginx config | covered | rr: Restart refuses a broken nginx config and keeps the site serving |
| reload (no flag, `--nginx`, `--all`), custom config applied | covered | rr: Reload nginx... (outline); Custom nginx config is applied on reload |
| reload with a broken config | covered (known bug) | rr: Reload fails when the nginx config test fails |
| `custom-compose` config branch of restart/enable | not covered | needs a custom compose setup (config change) |
| clean: no flags, `--object`, `--page`, `--proxy` without proxy cache | covered | clean: first four scenarios |
| clean `--proxy` with proxy cache | covered (@destructive, known bug CACHE-1 pattern) | clean: Cleaning the proxy cache empties it |
| clean without flags on a proxy-cached html site | covered (known bug) | clean: Cleaning without flags on a proxy-cached html site succeeds |
| clean object/page caches with redis | not covered | only WP cache sites have them (site-type-wp suite) |

### update

| Item | Status | Scenarios |
|---|---|---|
| `--add-alias-domains` (DB, env, HTTP, info) | covered | alias: Add alias domains |
| already present / partial overlap warning | covered | alias: Adding an alias that is already present fails; Adding present and new aliases... |
| `--delete-alias-domains`, primary, not a subset | covered | alias: Delete an alias domain; The primary domain cannot be deleted; Deleting an alias the site does not have fails |
| alias that is a site / alias of another site | covered | alias: Another site cannot be added...; An alias of another site cannot be added |
| add + delete in one call, wildcard alias | covered | alias: Add and delete aliases in one call; A wildcard alias serves every subdomain |
| alias change on self (keeps HTTPS), on LE (reissue), LE alias failing validation | covered | alias: Changing aliases on a self-signed site keeps HTTPS; le: alias scenarios; known bug SSL-2 |
| alias label validation (AUTH-1) | covered (regression; fixed by #502, merged 2026-09-25) | alias: Alias domains with a label starting with an underscore are rejected |
| alias change on custom SSL (warning) | not covered | low value; same code path as self apart from the warning text |
| `--ssl=self` on a plain site, twice, `--ssl=off`, off on plain | covered | ussl: first four scenarios |
| `--wildcard` refusals, invalid `--ssl`, `--ssl=off --wildcard` | covered | ussl: Switching a normal site to wildcard SSL is refused; An unknown SSL type is rejected; --wildcard cannot be combined with --ssl=off |
| `--ssl=inherit` via update (and the missing parent check) | covered | ussl: Inherit SSL from a self-signed parent through update; known bug: Inheriting SSL through update requires a wildcard parent |
| `--ssl=le` via update | covered (@le) | le: Enable Let's Encrypt on an existing site |
| `--ssl=custom` via update | covered (known bug SC-15) | ussl: Enable custom SSL on an existing site (PR #489 adds `--ssl-key/--ssl-crt`) |
| off on a created self-signed site, wildcard flag consistency | covered (known bugs) | ussl: SSL can be disabled on a site created with self-signed SSL; Enabling self-signed SSL through update records it as wildcard... |
| `--php` on html (fails, site intact), invalid version | covered | ussl: Changing the PHP version of an html site fails...; An unsupported PHP version is rejected; known bug SC-13: ...refused up front without leftovers |
| `--php` real version switch (config backup/restore, sendmail path for 5.6) | not covered | php/wp sites only (site-type-php/wp suites) |
| `--proxy-cache=on/off`, twice, custom sizes, invalid sizes | covered (@destructive) | pcache: all but the invalid switch |
| invalid `--proxy-cache` value | covered (@destructive, known bug SC-18) | pcache: An invalid proxy cache switch is rejected |
| proxy cache for aliases (existing and newly added) | covered (@destructive) | pcache: Alias domains get proxy cache locations... |
| proxy cache `subdom` location (`*.site_location`) | not covered | WP subdom only (site-type-wp suite) |

### SSL commands

| Item | Status | Scenarios |
|---|---|---|
| ssl-info: self, custom, inherit (parent cert file), no SSL, LE, pending wildcard | covered | ssl: ssl-info scenarios; le: create and wildcard scenarios |
| ssl-info `--format=yaml`, invalid format | covered | ssl: ssl-info supports other formats; ssl-info rejects an unsupported format |
| ssl-info `--get-dns-records`: non-DNS site, DNS-01 site | covered | ssl: ssl-info DNS records of a site that does not use DNS-01; le: A wildcard certificate waits... |
| ssl-info `--get-dns-records` on a self-signed (wildcard) site | covered (known bug SC-16) | ssl: ssl-info DNS records of a self-signed site |
| ssl-renew guards: no SSL, self, inherit | covered | ssl: ssl-renew only renews Let's Encrypt certificates (outline); ssl-renew refuses inherited certificates |
| ssl-renew not due (no reissue), `--all` wildcard skip | covered (@le, `--all` also @destructive) | le: ssl-renew leaves a certificate that is not due alone; ssl-renew --all skips wildcard sites... |
| ssl-renew `--force`, expired-cert path | not covered | LE budget (a forced renew is one more issuance); expiry cannot be simulated without a fake CA |
| ssl-verify / `ssl` alias: unknown site | covered | site: outline |
| ssl-verify on a valid LE site | covered (known bug SSL-8) | le: ssl-verify of a valid Let's Encrypt site succeeds |
| ssl-verify completing a DNS-01 order, Cloudflare DNS solver | not covered | needs Cloudflare API credentials (not configured) |
| Self-signed root CA validity (SEC-3), alias coverage (SSL-4) | covered (known bugs) | ssl: last two scenarios |
| SSL policy (`ssl-policy` config) | not covered | global config change (@destructive) with little behaviour beyond the template |

### share

| Item | Status | Scenarios |
|---|---|---|
| `--disable` / `--refresh` without an active share, SSL site refused, required name | covered | share: all; site: usage outline |
| Actual share (ngrok download, tunnel, `--token`), one share at a time, share cleanup on delete | not covered | the ngrok v2 binary URL (bin.equinox.io) is retired and ngrok needs an account token; external network |

### backup / restore (@remote)

| Item | Status | Scenarios |
|---|---|---|
| backup html: upload, success output, remote files (metadata.json, <site>.zip, conf.zip), local cleanup, lock released | covered | backup: Back up an html site |
| `--list` | covered | backup: Back up an html site; A disabled site cannot be backed up but its backups can be listed |
| `--list` with no backups | covered (known bug) | backup: Listing the backups of a site without backups says so |
| restore latest, restore `--id`, unknown id | covered | backup: Restore the latest backup; Restore a specific backup by id; Restoring an unknown backup id fails |
| disabled site | covered | backup: A disabled site cannot be backed up...; en-dis outline |
| `--dash-auth` format and ed-api-url check | covered | backup: A malformed --dash-auth is rejected; --dash-auth needs ed-api-url in the config |
| `--dash-auth` success/failure callbacks, rollback of the uploaded backup | not covered | needs an EasyDash API endpoint (`ed-api-url`) |
| per-site lock blocks, lock left by a failed restore | covered | backup: A lock file of another backup blocks the backup (held with flock(1), so it holds before and after PR #480); known bug SC-9: A failed restore does not block later backups |
| BKP-1 stale download reuse | covered (known bug) | backup: Restoring by id ignores files left behind... |
| retention (`no-of-backups`) | covered (known bug) | backup: Old backups are pruned down to no-of-backups |
| WP backup/restore (DB, wp-content, core download), LOG-3, WP-1 | covered (@wp) | backup: three @wp scenarios |
| custom docker-compose files in backups | not covered | needs a custom compose setup |
| disk-space checks, rclone/7z missing, apt-get auto-install (BKP-3), global backup lock wait | not covered | needs a full disk, uninstalled tools or a concurrent backup; BKP-3 needs a pending kernel upgrade |
| BKP-2 partial SQL dump in webroot | not covered | needs a failing mysqldump (fault injection) |

### clone / sync

| Item | Status | Scenarios |
|---|---|---|
| Location validation (implicit names, both remote, same site, malformed remote) | covered | clone: first four scenarios |
| Missing source, disabled source, html source, missing destination, html sync | covered | clone: remaining validation scenarios |
| Local WP clone (files, DB, search-replace), existing destination kept | covered (@wp) | clone: Clone a WordPress site on the same host; Cloning onto an existing site fails and keeps that site |
| sync `--files`, `--db`, `--uploads` | covered (@wp) | clone: Sync only files / the database / uploads |
| clone `--ssl=...`, `--wildcard`, cert copy, admin-tools/mailhog replication | not covered | same-host clone of an SSL WP site is possible but slow; LE options need the LE budget |
| Remote clone/sync over SSH, production guard (`env: production`), CLONE-1, CLONE-2 | not covered | needs a second SSH-reachable EE host (CLONE-1 needs a fake ssh shim); the production guard needs a global config change |

### Not in scope

DB and container migrations under `migrations/` (core upgrade flow), the AcmePhp repository classes (only through LE scenarios), macOS-only code.

## Coverage estimate

Counting the rows above as items: 113 items, 89 covered (14 of them only through `@known-bug` scenarios), 1 partial, 23 not covered, i.e. about 79% of the inventory (78% before the review). Every user-facing subcommand and option of the html type has at least one scenario except `ssl-renew --force`, `clone --ssl/--wildcard` and a live `share`.

## Product bugs found while writing the suite (issues.md SC-1..15)

Verified against develop 9dc44e1 in review; details, severity and locations are in `issues.md` ("site-command (from Behat authoring)").

| ID | Bug | Scenario | Open PR |
|---|---|---|---|
| SC-1 | `ltrim($site, 'www.')` strips characters, not the prefix | redirect: A www site whose next label starts with w... | none |
| SC-2 | `get_parent_of_alias()` uses `LIKE '%alias%'` | create: The owner of an alias is reported correctly... | none |
| SC-3 | SSL can't be turned off on a `--ssl=self` site; update stores `site_ssl_wildcard=0`, create 1 | ussl: two scenarios | none |
| SC-4 | `update --ssl=inherit` skips the parent check | ussl: Inheriting SSL through update requires a wildcard parent | none |
| SC-5 | inherit error message built with `var_dump()` | ssl: A failed inherit does not dump the parent site object | none |
| SC-6 | `site reload` exits 0 when `nginx -t` fails | rr: Reload fails when the nginx config test fails | none |
| SC-7 | `site clean` without flags always fails on html sites | clean: Cleaning without flags on a proxy-cached html site succeeds | none |
| SC-8 | EE v3 `--w3tc` message never shows | site: Removed EE v3 cache flags give a clear error | none |
| SC-9 | failed restore leaves `<site>.lock` and blocks later backups | backup: A failed restore does not block later backups | #480 (flock; the file stays but no longer blocks) |
| SC-10 | retention keeps `no-of-backups + 1` | backup: Old backups are pruned down to no-of-backups | #482 |
| SC-11 | "No remote backups found." unreachable | backup: Listing the backups of a site without backups says so | #482 |
| SC-12 | `copy_site_db()` ignores failed export/import | none (needs fault injection) | none |
| SC-13 | `update --php` on html fails late, raw IOException, leaves `.backup/` | ussl: ...refused up front without leftovers | none |
| SC-14 | `delete_site()` `docker rm` fallback filters on labels EE never sets | none (dead fallback) | none |
| SC-15 | `update --ssl=custom` impossible (no `--ssl-key/--ssl-crt`) | ussl: Enable custom SSL on an existing site | #489 |
| SC-16 | `ssl-info --get-dns-records` treats self-signed (wildcard) sites as DNS-01 | ssl: ssl-info DNS records of a self-signed site | none |
| SC-17 | local clone/sync on a host without sites: "Unable to get site list" | none (needs a host with no site; clone: Cloning a site that does not exist fails keeps one site) | none |
| SC-18 | `update --proxy-cache=<anything>` accepted, non-`on` disables | pcache: An invalid proxy cache switch is rejected | none |

## Other known bugs and the open PRs that fix them

| Scenario | Issue | Open PR |
|---|---|---|
| backup: Restoring by id ignores files left behind... | BKP-1 | #482 (f372636) |
| backup: Restoring a WordPress site does not print its database password | LOG-3 | #484 (success path only) |
| backup: A restored WordPress core passes checksum verification | WP-1 | none (local fix only) |
| create: Site names that are not valid hostnames are rejected... | SEC-1 | #495 is partial (self-signed sinks only); full fix local |
| le: SSL-2, SSL-8, SSL-5/SSL-9; ssl: SEC-3, SSL-4; clean: CACHE-1 pattern; redirect: MIG-15 | as named | none (#486/#487/#493/#495/#498/#502 only touch these paths) |

PRs that change what passing scenarios assert, to revisit when they merge: #480 keeps `<site>.lock` after a successful backup/restore (drop the two "lock should not exist" lines in backup), #482 adds a `--list` "No remote backups found." line and new disk-space errors, #484/#483 add new restore/backup abort messages, #486 changes the SSL-2 warning text, #487 makes `ssl-verify` fail for non-LE sites, #501 changes the clone messages on an empty destination host ("Unable to find '<site>' on '<host>'"). #502 merged on 2026-09-25: AUTH-1 is a regular scenario since, and "Delete an alias domain" waits for the remaining alias to answer 200 (the recreated nginx container briefly drops all of the site's domains from the proxy; https://github.com/mrrobot47/site-command/actions/runs/36099145415).
