# eFiction 3.5.5 maintenance fork (unofficial)

This is an unofficial eFiction 3.5.5 maintenance fork for existing archives. It is not affiliated with the original eFiction maintainers. For new archives, consider [Kiption](https://github.com/Hyperion360/kiption), a separate, from-scratch fiction platform. Kiption provides an eFiction export/import workflow; review its migration report and verify your archive before cutover.

Maintained by [Hyperion360](https://hyperion360.com/). The scope is compatibility repair: the same eFiction 3.5.5 PHP pages, templates, and schema, running on current PHP and database versions, with the security defects listed in [CHANGES-MAINTENANCE.md](CHANGES-MAINTENANCE.md) fixed. Every change is recorded there with its reproduction. There is no schema change and no new feature work.

## Tested requirements

- PHP 8.3 or 8.4 with the mysqli extension
- MySQL 8.0 or MariaDB 10.11 (10.6 also verified locally)
- Apache 2.4 with `.htaccess` enabled for the tested install path; any PHP-capable server works for evaluation
- A hosting file manager or FTP for upload. No shell, Composer, cron, or Node is required

PHP 8.3 is the supported floor, not a claim that older hosts cannot run it. Each later PHP minor needs revalidation before it is claimed. Testing status: verified in a local Apache 2.4 fixture and against the host matrix above; not tested on a commercial shared-host provider account.

## Install

Upload the release ZIP, extract it in your web root, place a one-time 64 hex character owner secret in a file beside the web root, and follow the installer in the browser. The secret requirement, the denial probe for that location, and the recovery rules are documented step by step in [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md). The installer writes `config.php` itself and deletes the secret when the install completes.

## Upgrade from eFiction 3.5.5

Back up your files and database, then upload the release ZIP over the existing files, keeping `config.php` and your uploaded `stories/` directory. The fork is file compatible with 3.5.5: same schema, no `update.php` migration, nothing to run. Archives older than 3.5.5 must reach a verified eFiction 3.5.5 copy first (upstream stable source: [eFiction/v3_stable](https://github.com/eFiction/v3_stable)).

## Migrating to Kiption

Kiption documents an export/import runbook: [Migrating from eFiction](https://github.com/Hyperion360/kiption/blob/main/README.md#migrating-from-efiction). Self-hosting Kiption is free and needs PHP 8.3+ with the pdo_sqlite extension. The exporter script runs on the old install and needs PHP 8.0+ with the zlib and phar extensions. The exported bundle contains member email addresses and legacy password hashes; treat it as a password file and delete it after the import. [Kiption Cloud](https://kiption.cloud) hosts archives, including eFiction migration on paid tiers.

Verify the import against your own archive before cutover: compare story, chapter, member, and rating counts and spot-check access restrictions on both sides.

## Security notes

Known release-blocking defects in eFiction 3.5.5 are fixed in this fork: the unpublished story access check, stored PHP execution in custom pages, missing write tokens on admin forms, and the unauthenticated legacy upgraders. The full defect inventory with reproductions lives in [CHANGES-MAINTENANCE.md](CHANGES-MAINTENANCE.md). Compatibility limits, such as custom pages containing PHP no longer executing, are listed in [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## License and credits

eFiction is Copyright (c) 2007 by Tammy Keefer, based on eFiction 1.1, Copyright (C) 2003 by Rebecca Smallwood. This fork keeps every upstream notice and ships the full text of the GNU General Public License, version 2 or later, in [LICENSE](LICENSE). Bundled third-party components (TemplatePower, PHPMailer, TinyMCE, two CC BY-NC skins) keep their own licenses: see [CREDITS.md](CREDITS.md). Import provenance and the upstream commit this fork starts from are recorded in [docs/PROVENANCE.md](docs/PROVENANCE.md).

## Development

Tests live in `tests/compat/`: a full-tree lint, a runtime probe, a database round trip, browser-install and upgrade harnesses, an Apache release gate under Docker, a security role matrix, and packaging checks. CI runs the same gates on PHP 8.3 and 8.4 against MySQL 8.0 and MariaDB 10.11 (see `.github/workflows/compat.yml`).
