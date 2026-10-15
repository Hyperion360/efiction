# Compatibility and deployment

This document describes what the maintenance fork is tested on, how to install it on an ordinary shared host with only a file manager and a browser, and how to upgrade a 3.5.5 archive. Last updated: 2026-10-14.

## Tested hosts

The fork is tested, not merely built, on each row of this matrix. Each later PHP minor needs revalidation before it is claimed.

| Component | Tested |
| --- | --- |
| PHP | 8.3.35 and 8.4.8 (CLI server and Apache 2.4 with mod_php in the release gate) |
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

## Partial install recovery

If an install stops between steps, the installer offers one recovery action on step 1. It requires the owner secret and only proceeds when a state marker written next to the secret matches the SHA-256 of the existing `config.php` and the database holds no admin, story, chapter, message, news, or review rows. Recovery saves a file and database backup into a private `efiction-install-backup-*` directory, removes `config.php`, and lets you start over at step 1.

An existing or uncertain archive never qualifies: a missing marker, a mismatched hash, or any content rows all fail closed with no changes. Restore from your own backup instead. Once the owner secret is gone (a completed install), no recovery is possible and the installer is inert.

## Upgrade from eFiction 3.5.5

The fork is file compatible with eFiction 3.5.5. There is no schema change and no `update.php` migration: `update.php` in this fork performs no database writes at all.

1. Back up your files and your database first.
2. Upload the release ZIP contents over the existing files with your file manager, preserving `config.php` and your uploaded `stories/` directory. Both are excluded from the release ZIP so an overwrite cannot clobber them.
3. Reload your site. Reader and admin pages work as before. `update.php` reports that no database update is needed and writes nothing.

Archives older than 3.5.5 must first reach a verified eFiction 3.5.5 copy (the upstream stable source: https://github.com/eFiction/v3_stable). The fork does not provide in-place upgrades from older versions. Any future fork release that changes the schema needs its own tested admin-only updater.

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
