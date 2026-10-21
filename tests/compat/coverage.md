# Coverage map

Every changed behavioral branch of the maintenance fork, mapped to the named case that executes it. All cases run in the local verification matrix (PHP 8.3.35 and 8.4.8 x MySQL 8.0.46 and MariaDB 10.6.23) and in CI (PHP 8.3 and 8.4 x MySQL 8.0 and MariaDB 10.11); the Apache cells add the extracted-ZIP release gate on Apache 2.4 with MariaDB 10.11. Legacy whole-tree line coverage is not a meaningful target for this maintenance fork; changed branches are 100% mapped below.

## Bootstrap and runtime (Task 2, commit `fix: remove PHP 8 runtime blockers`)

| Branch | Case |
| --- | --- |
| header.php magic-quotes block removed | `tests/compat/runtime.php`: page bootstrap loads corefunctions on PHP 8.3+ |
| `get_magic_quotes_gpc()` removal (item 5) | every http.sh/apache.sh request renders with no fatal |
| `$words[$j]{0}` to `[0]` (item 3) | runtime.php: word masking and multibyte legacy byte behavior |
| session salt `md5($email + $hash)` to concatenation (item 6) | runtime.php: arithmetic salt is a TypeError on PHP 8, concatenated salt matches, login.php and get_session_vars.php contain no arithmetic salt |
| PHPMailer magic-quotes runtime removal (item 8) | runtime.php: encodeFile runs on PHP 8 |
| series.php ternary, admin/stories.php paren (items 1-2) | lint.sh parses the full tree on PHP 8.3 and 8.4 |
| `__autoload()` removal (item 4) | lint.sh plus runtime.php requiring the autoloader path |
| `$_CHARSET` before language include (item 10) | http.sh fresh: home page loads with no PHP errors |

## Database boundary (Task 3, commits `fix: support current MySQL and MariaDB defaults`, `fix: complete a full install on PHP 8.3 and MySQL 8 defaults`)

| Branch | Case |
| --- | --- |
| MYSQL40 statement removed (item 7) | database.php round trip on both engines, both PHP minors |
| dbquery exception routing | database.php: failing query does not leak SQL to guests |
| connect failure path | database.php: connect failure does not echo credentials |
| apostrophe/Unicode, NULL, empty string | database.php round trips |
| `?debug` admin only | http.sh fresh: guest requests render without debug output |
| zero-date defaults under MySQL 8 (item 13) | http.sh fresh: install creates all 27 tables on MySQL 8.0 |

## Install, upgrade, deployment (Task 4, commit `fix: make shared-host install and upgrade safe on PHP 8.3`, `test: add the Apache release gate, packaging, and compatibility guide`)

| Branch | Case |
| --- | --- |
| Owner secret absent | http.sh fresh: installer locked, status only |
| Secret missing / wrong / CSRF missing / forged Origin | http.sh fresh: each POST denied 403, config and table counts unchanged |
| Valid POST writes config | http.sh fresh: atomic write, state marker hash matches, not world writable |
| Anonymous session after config write | http.sh fresh: cannot resume or overwrite |
| Recovery: marker/hash mismatch | http.sh fresh: denied 403 |
| Recovery: content rows present | http.sh fresh: denied 403 |
| Recovery: valid partial install | http.sh fresh: backup saved, config and marker removed, installer deactivated |
| Config value quoting | http.sh fresh: quote/semicolon payload stored as a literal, never executes |
| POST-only steps 2 to 9 | http.sh fresh: GET install=automatic mutates nothing; steps complete with secret |
| Repeated admin step | http.sh fresh: no second admin |
| Completed install | http.sh fresh: secret and marker removed, all later actions rejected, config hash stable |
| Fresh install smoke | http.sh fresh + apache.sh fresh: home, admin login, account page, logout, no PHP errors |
| 27-table install | http.sh fresh on MySQL 8.0 and MariaDB 10.6/10.11 |
| Installer PHP 8 fatals and warnings (items 11, 12, 15, 16) | http.sh fresh: every installer step renders with zero warnings/deprecations |
| File-overwrite upgrade preserves state | http.sh upgrade + apache.sh upgrade: schema, preserved rows, config, and story file hashes unchanged across two replacements |
| update.php no-write | http.sh upgrade: GET confirm renders status, writes nothing, rejects pre-3.5.5 |
| Upgrade smoke on preserved fixture | http.sh upgrade: admin login, home, story, edit form, custom page load with no PHP errors |
| Apache release path | apache.sh fresh and upgrade on PHP 8.3 and 8.4 images: ZIP extracted, .htaccess active, config.php denied over HTTP, token not web readable |
| Packaging | package.sh gates: no config.php, tokens, tests, backups in the ZIP; upstream notices present |

## Authorization and stored code (Task 5, commit `fix: close the unpublished story and stored code defects`)

| Branch | Case |
| --- | --- |
| Unvalidated story: guest / unrelated member | security.php: denied, chapter text absent |
| Unvalidated story: owner / coauthor / admin | security.php: title and chapter text visible |
| Validated story: guest | security.php: chapter text visible |
| Nonexistent story id | security.php: safe message, no PHP warnings |
| Custom page with PHP: public view | security.php: no execution, no source exposure, no payload text, 503 notice |
| Plain custom page | security.php: renders exactly as stored |
| Admin preview with PHP draft | security.php: review warning, no execution, no source |
| Forged custom page submit | security.php: 403, row count unchanged |
| Tokened custom page submit | security.php: 200, row inserted |
| GET delete of a custom page | security.php: denied, row survives |
| Forged message edit with quoted name | security.php: denied, row unchanged |
| Tokened message edit | security.php: row updated |
| Legacy upgraders disabled (item 24) | direct request exits with guidance; lint parses both files |

## New files added by this fork

`LICENSE`, `CREDITS.md`, `docs/PROVENANCE.md`, `docs/COMPATIBILITY.md`, `INSTALL.md`, `UPGRADE.md`, `CHANGES-MAINTENANCE.md`, `README.md`, `.gitignore`, `.htaccess`, `tests/compat/*`, `tests/fixtures/*`, `.github/workflows/compat.yml`. All runtime files retain their upstream license headers.
