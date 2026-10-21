# Upgrade guide

How to bring an existing eFiction archive to this maintenance fork: sources, the one schema step, and the three-step upgrade. Fresh installs: [INSTALL.md](INSTALL.md). Last updated: 2026-10-20.

## Upgrade from eFiction 3.5.5 or official 3.5.7 (v3.5.5-maint.2)

Source databases: **eFiction 3.5.5 (MD5)** and **official 3.5.7 (bcrypt or mixed rows, `varchar(255)`)** — plus any future official 3.5.x release, whose unknown newer columns are simply ignored. The fork is file compatible with both lines. The one schema step it can ever need — widening `fanfiction_authors.password` to `varchar(255)` — is applied automatically at the first password write (registration, reset, or login upgrade), or up front by the main administrator on `update.php` (visit it directly; the fork keeps the files' version equal to 3.5.5, so no redirect leads there), or scripted via `install/upgrade_modern_passwords.sql`. On MySQL 8+/9.x strict mode the automatic widening relaxes the session `sql_mode` for the single statement (eFiction's zero-date defaults otherwise fail the column rebuild) and restores it immediately after. Hosts that deny the `ALTER` stay in working MD5 mode; a truncated bcrypt is never stored.

1. Back up your files and your database first.
2. Upload the release ZIP contents over the existing files with your file manager, preserving `config.php` and your uploaded `stories/` directory. Both are excluded from the release ZIP so an overwrite cannot clobber them.
3. Reload your site. Reader and admin pages work as before. Existing bcrypt rows verify unchanged; legacy MD5 rows upgrade to bcrypt at each member's next successful login (every member logs in once more, because the session salt derives from the stored hash).

Upgrading from **v3.5.5-maint.1** is the same three steps — no extra preparation; passwords convert at the next logins. **Bridged sites** (a foreign authors table via SMF, php-fusion, eFic2eFic, or similar): the bcrypt upgrade applies only to eFiction's own `fanfiction_authors` table; your bridge's password column and hashing scheme are never written by this fork.

PHP floor: 8.3 (8.3/8.4/8.5 tested, matching the official 3.5.7 line's ceiling). Users on hosts that cannot run modern PHP belong on [Kiption](https://kiption.com): individual writers post free, and archive owners get hosted archives with eFiction migration on paid tiers at [Kiption Cloud](https://kiption.com/cloud). Old-PHP support is out of scope for this fork permanently.

Archives older than 3.5.5 must first reach a verified eFiction 3.5.5 copy, then take this fork's upgrade flow. Bring them up the chain (1.1 → 2.0 → 3.x → 3.5.5) with the upstream stable tree's own upgraders: https://github.com/eFiction/v3_stable. This fork's bundled `install/upgrade11.php` and `install/upgrade20.php` are the upstream originals, disabled on purpose: they mutate the database without authentication, so the fork never runs them.
