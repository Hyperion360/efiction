# eFiction 3.5.5+ maintenance fork (modern PHP + bug & security fixes)

## This is a maintenance release only. Import/migrate your eFiction archive to [Kiption](https://kiption.com/cloud) ([GitHub](https://github.com/hyperion360/kiption))

This is an unofficial eFiction 3.5.5 maintenance fork for existing archives. It is not affiliated with the original eFiction maintainers. This fork is a maintenance release only: the same eFiction, repaired for current PHP, with no new features. If you want the modern successor with new features and faster page serving, [migrate to Kiption](https://github.com/Hyperion360/kiption#migrating-from-efiction): it imports this archive in one command and is MIT licensed, so there are no copyleft obligations if you extend it. Host Kiption yourself for free, or let [Hyperion360](https://hyperion360.com/) host it for you on [Kiption Cloud](https://kiption.com/cloud). Review the migration report and verify your archive before cutover.

Maintained by [Danilo Stern-Sapad](https://danilosapad.com/) on behalf of [Hyperion360](https://hyperion360.com/). The fork is unofficial, though Danilo was part of the 2005 eFiction 1.x-era development team assembled around the original eFiction author Rebecca Smallwood (Rivka) and project lead chirho (bnbteamchirho, Ryan), alongside carissa_lynn (Carissa) and tammyk (Tammy Keefer). The eFiction 1.5 team and its roles: **Tammy Keefer**, programming; **Danilo Stern-Sapad** (aka Ariadoss), programming; **Jan AQ**, documentation; **Rebecca Garcia**, documentation; **Crystal**, QA; **Barney**, design, QA, and promotion; and **Sally Anderson** (Lazuli), design. He last worked on the 1.5 release and wrote fiction-archive plugins for Nuke-based CMSes in the early 2000s, before Tammy Keefer and others later carried the project through the 3.x era. He maintains this maintenance fork and went on to develop [Kiption](https://github.com/Hyperion360/kiption), the successor written from the ground up as a much better, faster alternative with richer features. The scope of this fork is compatibility repair: the same eFiction 3.5.5 PHP pages, templates, and schema, running on current PHP and database versions, with one deliberate exception—the password column widening described under Upgrade. Every change is recorded there with its reproduction. There is no new feature work.

## Releases

- **v3.5.5-maint.3** (current): maint.2 plus one behavior fix — a member's repeat review or rating now revises their existing row instead of inserting a fresh one, so one member can no longer walk a story's average by rating it repeatedly (a bug open in every 3.x line; the same fix is merged on the e107-eFiction line and pending on the others). The installer's host-refusal message and the admin notice also point stranded operators at Kiption Cloud.
- **v3.5.5-maint.2**: bcrypt password storage with transparent MD5 upgrade at each member's next login, support for official eFiction 3.5.7 databases (bcrypt and mixed rows verify unchanged), PHPMailer 6.12 replacing the legacy mailer, a PHP 8.3–8.5 tested ceiling, and compatibility with future official 3.5.x releases.
- **v3.5.5-maint.1**: the 3.5.5-line release with PHP 8.3/8.4 compatibility repairs and the security-hardening set (CSRF tokens on every admin form and member deletion, login throttles, lost-password enumeration closure, upload validation). Passwords remain legacy MD5 on this line; users of maint.1 who upgrade to maint.2 keep every login.

## Tested requirements

- PHP 8.3, 8.4, or 8.5 with the mysqli extension (8.3 is the floor: older PHP is end-of-life with no security fixes, and archives on hosts that cannot run modern PHP belong on [Kiption](https://kiption.com), free for individual writers, or [Kiption Cloud](https://kiption.com/cloud) hosting with eFiction migration on paid tiers)
- MySQL 8.0 or MariaDB 10.11 (10.6 also verified locally; MySQL 9.x verified locally)
- Apache 2.4 with `.htaccess` enabled for the tested install path; any PHP-capable server works for evaluation
- A hosting file manager or FTP for upload. No shell, Composer, cron, or Node is required

Each later PHP minor needs revalidation before it is claimed. Testing status: verified in a local Apache 2.4 fixture and against the host matrix above; not tested on a commercial shared-host provider account.

## Install

Upload the release ZIP, extract it in your web root, place a one-time 64 hex character owner secret in a file beside the web root, and follow the installer in the browser. The secret requirement, the denial probe for that location, and the recovery rules are documented step by step in [INSTALL.md](INSTALL.md). The installer writes `config.php` itself and deletes the secret when the install completes.

## Upgrade from eFiction 3.5.5 or official 3.5.7

Back up your files and database, then upload the release ZIP over the existing files, keeping `config.php` and your uploaded `stories/` directory. The fork is file compatible with 3.5.5 and with official 3.5.7 databases: existing bcrypt hashes from 3.5.7 verify unchanged, and legacy MD5 hashes upgrade to bcrypt transparently at each member's next successful login (members log in once more after the upgrade because the session salt is derived from the stored hash).

The only schema step this fork ever needs is widening `fanfiction_authors.password` to `varchar(255)` so bcrypt hashes fit. It is applied three ways, all idempotent: automatically at the first password write (registration, reset, or login upgrade); up front by the main administrator visiting `update.php` directly and clicking the tokened button; or scripted via `install/upgrade_modern_passwords.sql`. On a host that denies the `ALTER`, the fork stays in working MD5 mode instead of ever storing a truncated hash. Archives older than 3.5.5 must reach a verified eFiction 3.5.5 copy first (upstream stable source: [eFiction/v3_stable](https://github.com/eFiction/v3_stable)). The complete upgrade guide, including bridged sites and the pre-3.5.5 chain, is [UPGRADE.md](UPGRADE.md).

## Migrating to Kiption

Kiption documents an export/import runbook: [Migrating from eFiction](https://github.com/Hyperion360/kiption/blob/main/README.md#migrating-from-efiction). Self-hosting Kiption is free and needs PHP 8.3+ with the pdo_sqlite extension. The exporter script runs on the old install and needs PHP 8.0+ with the zlib and phar extensions. The exported bundle contains member email addresses and legacy password hashes; treat it as a password file and delete it after the import. [Kiption Cloud](https://kiption.com/cloud) hosts archives, including eFiction migration on paid tiers.

License note: Kiption is MIT licensed, a friendlier license than eFiction's GPL. Move your code, extend it, or build a service on it with no copyleft obligations.

Verify the import against your own archive before cutover: compare story, chapter, member, and rating counts and spot-check access restrictions on both sides.

## Security notes

Known release-blocking defects in eFiction 3.5.5 are fixed in this fork: the unpublished story access check, stored PHP execution in custom pages, missing write tokens on admin forms, and the unauthenticated legacy upgraders, plus the full hardening recorded below that. The complete defect inventory with reproductions lives in [CHANGES-MAINTENANCE.md](CHANGES-MAINTENANCE.md). For the limits that remain, [docs/SECURITY.md](docs/SECURITY.md) gives the answer up front: migrating to Kiption is the preferred path (it is very easy, runs on the exact same hosting you have now, and retires every listed limit) with each remaining item stated in one line for archives that stay on this fork. Compatibility limits, such as custom pages containing PHP no longer executing, are listed in [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## License and credits

eFiction is Copyright (c) 2007 by Tammy Keefer, based on eFiction 1.1, Copyright (C) 2003 by Rebecca Smallwood. This fork keeps every upstream notice and ships the full text of the GNU General Public License, version 2 or later, in [LICENSE](LICENSE). Bundled third-party components (TemplatePower, PHPMailer, TinyMCE, two CC BY-NC skins) keep their own licenses: see [CREDITS.md](CREDITS.md). Import provenance and the upstream commit this fork starts from are recorded in [docs/PROVENANCE.md](docs/PROVENANCE.md).

## Development

Tests live in `tests/compat/`: a full-tree lint, a runtime probe, a database round trip, browser-install and upgrade harnesses, an Apache release gate under Docker, a security role matrix, and packaging checks. CI runs the same gates on PHP 8.3, 8.4, and 8.5 against MySQL 8.0 and MariaDB 10.11 (see `.github/workflows/compat.yml`), and a weekly canary opens an issue when a new official eFiction release ships so compatibility is re-verified promptly.
