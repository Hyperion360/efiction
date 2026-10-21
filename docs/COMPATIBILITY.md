# Compatibility notes

What the maintenance fork changes about upstream behavior, and the record of the Kiption migration drill. Installation: [INSTALL.md](INSTALL.md). Upgrades: [UPGRADE.md](UPGRADE.md). Last updated: 2026-10-20.

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
