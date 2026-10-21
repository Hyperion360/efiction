# Compatibility and deployment

This document is the complete install and upgrade guide: what the maintenance fork is tested on, how to install it on an ordinary shared host with only a file manager and a browser, how to upgrade a 3.5.5 or official 3.5.7 archive, and what to do when a step misbehaves. Last updated: 2026-10-20.

## Choosing a release

- **v3.5.5-maint.2** (current) for new installs and every upgrade: bcrypt passwords with transparent MD5 upgrade, official 3.5.7-database support, PHP 8.3-8.5. Download it from the [releases page](https://github.com/Hyperion360/efiction/releases).
- **v3.5.5-maint.1** only if you deliberately want to stay on legacy MD5 passwords; upgrading from it to maint.2 later keeps every login.

## Tested hosts

The fork is tested, not merely built, on each row of this matrix. Each later PHP minor needs revalidation before it is claimed.

| Component | Tested |
| --- | --- |
| PHP | 8.3.35 and 8.4.8 (CLI server and Apache 2.4 with mod_php in the release gate); 8.5 exercised by CI |
| Database | MySQL 8.0.46 and MariaDB 10.6.23 (local matrix), MariaDB 10.11 (Apache release gate and CI service) |
| Web server | Apache 2.4 with `.htaccess` enabled and mod_rewrite (release gate); the PHP built-in server is used only as a fast supplemental smoke |
| PHP extensions | mysqli. mbstring is not required. No cron, Node, Composer, shell access, or root access is needed to install |

The installer stores chapter text either in the database or in files under a `stories/` directory; both modes are exercised by the fixture tests.

## Install on a shared host (file manager only)

1. Upload the release ZIP and extract it in a directory the web server serves. The extracted tree must contain `index.php`, `install/`, `includes/`, and `.htaccess`.
2. Create a MySQL or MariaDB database and a database user through your hosting control panel.
3. Create the installer owner secret. With your file manager, create a file named `efiction-install-token.txt` in the private directory ABOVE your web root (the parent of the directory that holds `index.php`; for a docroot like `~/public_html` that is `~`). Put 64 random hex characters in it, for example from an online generator or your control panel's shell-less random string tool. Check that the location is not web reachable: request `https://your-site.example/../efiction-install-token.txt` and any path you know of above the docroot and confirm the server denies or misses. On hosts where the docroot parent is itself inside the web root, ask the host how to serve only the docroot; the installer refuses to run without this file, and it deletes the file when the install completes.
4. Open `https://your-site.example/install/install.php` in a browser. Every mutating installer step requires the secret from that file, pasted into the form on the first step, plus a per-session token the forms carry automatically. Steps are POST-only; the old GET links from eFiction 3.5.5 mutate nothing.
5. Complete steps 1 to 9: database credentials, settings table, content tables, panels, links, blocks, messages, profile fields, and the admin account. The installer writes `config.php` itself with restrictive permissions (0640, or 0644 when the host requires it). Do not make `config.php` or the `stories/` directory world writable; owner-writable (0755) is enough.
6. When step 9 finishes, the installer deletes the owner secret and the state marker and is permanently disabled. Delete the `install/` directory as well.
7. Log in at `user.php?action=login` and configure the archive in the admin area.

`config.php` holds database credentials. The shipped `.htaccess` denies direct web requests for it under Apache. It must never be committed, copied into a release, or sent to anyone.

### Post-install checklist

1. Request `https://your-site.example/install/` and confirm it reports the installer is disabled; then delete the `install/` directory if you have not already.
2. Confirm `efiction-install-token.txt` is gone from the directory above the web root (the installer deletes it; verify).
3. Log in and change the admin password to one only you know (it is stored bcrypt-hashed from the moment you set it).
4. Leave `config.php` at its installed permissions (0640/0644); owner-writable is enough, and nothing needs to be world writable. The `stories/` directory is created 0777 for shared-host compatibility; on hosts where PHP runs as your own user, 0755 is safe and can be tightened after uploads work.
5. Take a first backup (files plus a database dump from your control panel) before you start importing stories.
6. Reader-facing documentation for skins, blocks, and the admin manual ships inside the ZIP's `docs/` folder (`adminmanual.htm`, `creating_skins.htm`, `creating_blocks.htm`, `convertingskins.htm`) — upstream's original guides, kept unmodified.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Installer says the site is "already configured" | A completed install exists. The installer is inert and safe to leave; to reinstall deliberately, delete `config.php` first (your database content is not touched by deleting the file). |
| Installer refuses to run with no secret prompt | `efiction-install-token.txt` is missing, misnamed, or not in the directory **above** the web root. The file must contain 64 hex characters, and `https://your-site.example/../efiction-install-token.txt` must deny or miss. On hosts that serve the docroot parent, ask the host to serve only the docroot. |
| Blank page or HTTP 500 anywhere | Almost always PHP below 8.3. Check the PHP version your host actually assigns to the directory (control panel or a `phpversion()` probe); switch it to 8.3, 8.4, or 8.5. Fatals from a broken upload (partially extracted ZIP) also present this way; re-extract. |
| "Call to undefined function mysqli_connect" or similar | The mysqli PHP extension is not enabled for the directory. Enable it in the host's PHP settings; mbstring is not required. |
| An installer step re-renders with a database error | The database name, user, or password is wrong, or the user lacks rights on the database. The form re-renders with the error text instead of failing silently; correct the values and resubmit. |
| The widen button on `update.php` reports the host refused | The database user lacks ALTER privilege, or MySQL rejected the rebuild. The fork stays in working MD5 mode — no hash was written. Apply `install/upgrade_modern_passwords.sql` by hand (with `SET SESSION sql_mode = ''` first on MySQL 8+/9.x), or ask the host to widen `fanfiction_authors.password` to `varchar(255)`. |
| Every member reports one extra login after an upgrade | Expected and one-time: the session salt derives from the stored hash, so the first login after the files change re-establishes it. Nothing is broken. |
| Admin actions return 403 | The POST lacked the per-session token (a bookmarked form page, or a stale tab). Reload the admin page and submit again. |
| The whole site shows the maintenance page | Maintenance mode is on in the site settings; an administrator can turn it off in the admin area. Unlike old 3.x upgrades, swapping in this fork's files never puts the site into maintenance by itself. |

## Partial install recovery

If an install stops between steps, the installer offers one recovery action on step 1. It requires the owner secret and only proceeds when a state marker written next to the secret matches the SHA-256 of the existing `config.php` and the database holds no admin, story, chapter, message, news, or review rows. Recovery saves a file and database backup into a private `efiction-install-backup-*` directory, removes `config.php`, and lets you start over at step 1.

An existing or uncertain archive never qualifies: a missing marker, a mismatched hash, or any content rows all fail closed with no changes. Restore from your own backup instead. Once the owner secret is gone (a completed install), no recovery is possible and the installer is inert.

## Upgrade from eFiction 3.5.5 or official 3.5.7 (v3.5.5-maint.2)

Source databases: **eFiction 3.5.5 (MD5)** and **official 3.5.7 (bcrypt or mixed rows, `varchar(255)`)** — plus any future official 3.5.x release, whose unknown newer columns are simply ignored. The fork is file compatible with both lines. The one schema step it can ever need — widening `fanfiction_authors.password` to `varchar(255)` — is applied automatically at the first password write (registration, reset, or login upgrade), or up front by the main administrator on `update.php` (visit it directly; the fork keeps the files' version equal to 3.5.5, so no redirect leads there), or scripted via `install/upgrade_modern_passwords.sql`. On MySQL 8+/9.x strict mode the automatic widening relaxes the session `sql_mode` for the single statement (eFiction's zero-date defaults otherwise fail the column rebuild) and restores it immediately after. Hosts that deny the `ALTER` stay in working MD5 mode; a truncated bcrypt is never stored.

1. Back up your files and your database first.
2. Upload the release ZIP contents over the existing files with your file manager, preserving `config.php` and your uploaded `stories/` directory. Both are excluded from the release ZIP so an overwrite cannot clobber them.
3. Reload your site. Reader and admin pages work as before. Existing bcrypt rows verify unchanged; legacy MD5 rows upgrade to bcrypt at each member's next successful login (every member logs in once more, because the session salt derives from the stored hash).

Upgrading from **v3.5.5-maint.1** is the same three steps — no extra preparation; passwords convert at the next logins. **Bridged sites** (a foreign authors table via SMF, php-fusion, eFic2eFic, or similar): the bcrypt upgrade applies only to eFiction's own `fanfiction_authors` table; your bridge's password column and hashing scheme are never written by this fork.

PHP floor: 8.3 (8.3/8.4/8.5 tested, matching the official 3.5.7 line's ceiling). Users on hosts that cannot run modern PHP belong on [Kiption](https://kiption.com): individual writers post free, and archive owners get hosted archives with eFiction migration on paid tiers at [Kiption Cloud](https://kiption.com/cloud). Old-PHP support is out of scope for this fork permanently.

Archives older than 3.5.5 must first reach a verified eFiction 3.5.5 copy, then take this fork's upgrade flow. Bring them up the chain (1.1 → 2.0 → 3.x → 3.5.5) with the upstream stable tree's own upgraders: https://github.com/eFiction/v3_stable. This fork's bundled `install/upgrade11.php` and `install/upgrade20.php` are the upstream originals, disabled on purpose: they mutate the database without authentication, so the fork never runs them.

## Custom pages with embedded PHP

eFiction 3.5.5 executed PHP embedded in custom pages (`fanfiction_messages` rows) through `eval`. The fork no longer executes stored PHP. A custom page whose text contains PHP delimiters (`<?php`, `<?=`, or `?>`) returns a generic notice asking the administrator to review the page; its source is never shown or run. Administrators should convert PHP-bearing pages to static HTML. The admin custom-page preview shows the same notice instead of executing.

## Administrative edits

Admin forms touched by this fork (custom pages, message settings) require a per-session token in the form; forged or missing tokens return 403 without changing data. Delete actions are POST-only.

## Migrating to Kiption

Kiption, like every repair in this fork, is the work of [Danilo Stern-Sapad](https://danilosapad.com/) on behalf of [Hyperion360](https://hyperion360.com/); he wrote the successor from the ground up as a much better, faster alternative with richer features. Kiption documents an export/import runbook: [Migrating from eFiction](https://github.com/Hyperion360/kiption/blob/main/README.md#migrating-from-efiction). The fork does not vendor or host the exporter; Kiption's `import:token` command prints the token, `resources/efiction-export.php` uploads to this archive's web root, and the bundle it produces is a password file (member emails and legacy hashes) that must be deleted after the import.

A migration drill was run on 2026-10-14 against a disposable installed archive built from this fork: 5 stories, 5 chapters (2 on-disk via `store=files`, 3 in the database), 5 members including a coauthor relation, 8 ratings with and without warning text, 2 custom pages (one plain, one containing PHP), and restricted navigation links. Kiption's published exporter produced the bundle; `import:efiction --dry-run` and then `--commit` imported it. Results, stated as the importer reports them:

- Imported completely: stories 5/5, members 5/5, categories 2/2, ratings 8/8, class types and classes 5/5, the coauthor relation, and every chapter whose text was stored in the database.
- Chapters stored on disk (`store=files`) were rejected as missing text (2 of 5). An archive running `store=files` must bring chapter text into the database, or move the files, before the import; the drill's copies never arrived.
- Messages without a matching page link were rejected (8 of 11), and custom or unmapped navigation link URLs were rejected (26 of 29). Pages and links need Kiption-side navigation entries before import.
- Warning acknowledgment gates were not migrated (the importer reports this itself): rating warnings do not become Kiption age or content gates in this path.
- Word counts are recomputed on import (the drill reported a recomputed-versus-legacy drift).

The migration path exists and runs; fidelity is partial on the classes above. Verify against your own archive before cutover: compare story, chapter, member, and rating counts and spot-check restricted content on both sides.

## Known compatibility limits

- Third-party bundled components (PHPMailer, TemplatePower, TinyMCE) are repaired only where PHP 8 reachable failures were proven; see CHANGES-MAINTENANCE.md for the full record.
- The two CC BY-NC skins (Berrygood, E-zfiction) keep their license files and required credit links; see CREDITS.md.
- The fork is labeled local-Apache-fixture-tested and host-matrix-tested, not provider-tested: no commercial shared host account was used for the release testing.
