# Installation guide

The complete guide to installing the eFiction maintenance fork on an ordinary shared host with only a file manager and a browser. For upgrades see [UPGRADE.md](UPGRADE.md); compatibility limits and the Kiption migration record live in [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md). Last updated: 2026-10-20.

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
