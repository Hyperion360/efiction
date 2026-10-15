# Security advisory: known limits of the maintenance fork

**Maintainer:** Danilo Stern-Sapad (https://danilosapad.com/) on behalf of Hyperion360 Inc
**Applies to:** the eFiction 3.5.5 maintenance fork, as of 2026-10-15 (release commit `3b98442`)
**Status:** active — reviewed before release; re-reviewed before each release

This fork repairs the defects listed in [CHANGES-MAINTENANCE.md](../CHANGES-MAINTENANCE.md)
(items 1-56 at the time of writing, each with its reproduction). This document is the
complement: the security-relevant limits that **cannot be fixed** within this fork's
contract (compatibility repair only: same schema, same features, same file layout) or
that we **will not fix** because the fix would break the shared-host audience this fork
serves. Each entry states the impact honestly, why it stays, and what an operator can do.

If you need these limits gone, the answer is migration: [Kiption](https://github.com/Hyperion360/kiption)
stores modern password hashes, ships a current editor, and imports this archive in one
command ([migration runbook](https://github.com/Hyperion360/kiption#migrating-from-efiction)).

## Summary

| # | Limit | Severity | Class |
|---|-------|----------|-------|
| 1 | Unsalted MD5 password hashing | High (on database compromise) | cannot fix — schema |
| 2 | Chapter text files readable by direct URL (bypasses the validation gate) | Medium | upstream design; operator mitigation below |
| 3 | TinyMCE 3.0.9 (2008) in the author editor | Medium (largely mitigated) | will not fix — vendored replacement out of contract |
| 4 | World-writable (0777) story and image directories | Medium on multi-tenant hosts | will not fix — shared-host compatibility |
| 5 | Remember-me tokens derivable from database values | Medium (on database compromise) | consequence of #1 |
| 6 | Member write actions other than deletes are not token-gated | Low | residual; SameSite=Lax covers modern browsers |
| 7 | Per-IP login lockout: NAT aggregation and distributed attacks | Low | design tradeoff |
| 8 | admin/backup.php serves the database dump on a GET | Low | documented; attacker cannot read the download |
| 9 | Cookies are not marked `secure` by default | Low | deployment decision (operator action below) |
| 10 | Admin-installed code blocks execute PHP by design | Informational | trust boundary, not a defect |

## 1. Passwords are unsalted MD5 — cannot be fixed without a schema change

`fanfiction_authors.password` is `varchar(40)` (docs/tables.sql:71) and stores a bare
`md5()` of the password. Modern `password_hash()` output needs 60+ characters, so the
upgrade requires widening the column — exactly the schema change this fork's contract
forbids (3.5.5 archives must upgrade by file replacement alone, with no `update.php` run).

**Impact:** anyone who obtains a database dump (backup leak, SQL injection elsewhere on
the host, compromised backup.php download) can recover weak passwords with rainbow
tables or offline brute force, and identical passwords are visible as identical hashes.
There is no server-side mitigation possible for the stored format itself.

**What operators should do:** protect the database and every dump of it as credential
material; enforce long member passwords; watch the upgrade path. A future fork release
that widens the column and rehashes on successful login is the in-place fix; Kiption's
importer carries the legacy hashes forward and is the supported exit.

## 2. Chapter text under `stories/` is readable by direct URL

With the default `store = files` setting, chapter text lives at
`stories/{uid}/{chapid}.txt` inside the web root, and the web server serves it
statically. `viewstory.php` gates unvalidated chapters behind the validation check, but
a direct URL fetch of the file never runs that gate. Ids are numeric and enumerable.

**Why it stays:** routing chapter text through PHP (or denying the files) changes the
upstream storage design and the file layout that existing archives and backup scripts
depend on. This fork repairs, it does not redesign.

**What operators should do:** prefer `store = database` for new archives. On Apache, the
fork's shipped `.htaccess` can gain a stanza that denies the text files without
affecting member images (PHP reads the files directly from the filesystem, so story
display is unaffected):

```apache
<FilesMatch "\.txt$">
    Require all denied
</FilesMatch>
```

Add it to `.htaccess` in the web root. Non-Apache servers need the equivalent rule at
the server level; the fork cannot enforce it from PHP.

## 3. TinyMCE 3.0.9 (2008) ships in the author editor

The bundled editor predates the 3.5.x security-era fixes; known advisories affect
advimage/media URL handling in that generation.

**Why it will not be fixed:** replacing the vendored `tinymce/` tree is a file
replacement plus integration rework (init API changed across major versions), which is
new-feature work outside the maintenance contract.

**Mitigation in place:** every save path runs the stored text through
`strip_tags($allowed_tags)` plus `descript()`, whose event-handler strip this fork
now covers for every `on*=` attribute instead of nine named handlers (CHANGES item 48,
with a pinned test matrix). The editor's client-side output is never trusted; the risk is confined to the
editing session of a logged-in author.

## 4. Story and image directories are created world-writable (0777)

`stories.php` and the upload class `chmod` the per-member directories to 0777
(stories.php:257 and siblings).

**Why it will not be fixed:** on the shared hosts this fork targets, PHP routinely runs
as a different user than the FTP account that owns the files; 0755 directories break
chapter saves and image uploads there. Tightening the mode trades a hypothetical
local-attacker risk for a certain functional break for the audience that needs this
fork most.

**What operators should do:** on hosts where PHP runs as the file owner (proper
php-fpm/suexec setup), `chmod 755 stories/*` is safe and can be re-applied after
upgrades. On multi-tenant machines where other local users are not trusted, 0777 web-
served directories are a webshell risk regardless of this software; that is a hosting
decision.

## 5. Remember-me tokens are derivable from database values

The long-lived cookie pair is `uid` plus `md5(email . password_hash)`
(user/login.php). Both inputs are database columns, so anyone who can read the database
can forge a valid remember-me cookie for every account without knowing any password.

**Class:** consequence of limit 1 — with hashes unsalted and short, the cookie is no
stronger than what it is built from. Changing the derivation would invalidate every
existing remember-me cookie (a forced re-login for all members) without fixing the
underlying exposure.

## 6. Member write actions other than delete confirmations are not token-gated

This fork's CSRF token gate covers the entire admin area and every delete confirmation
(story, chapter, series, challenge — admin and owner alike). Other member-facing writes
(submitting a story, editing a chapter, posting a review) still authenticate by session
alone.

**Mitigation in place:** session cookies are `SameSite=Lax`, so cross-site POSTs do not
carry them in current browsers; the write endpoints are POST-only.

**Residual risk:** browsers without SameSite support (pre-2017 engines) remain exposed
to classic CSRF on those forms. A future release may extend the token helper to the
member forms; it was scoped out here because those flows never had upstream GET
mutations, making the exposure strictly historical-browser.

## 7. The login lockout is per IP address

Ten failed logins from one address in fifteen minutes lock that address for fifteen
minutes (CHANGES item 45).

**Tradeoffs accepted:** behind carrier-grade NAT or a school/office proxy, ten aggregate
failures across different users lock everyone at that address (self-expiring; no admin
override). Conversely, a distributed attack rotating many addresses is never throttled —
the counter is per address and there is no per-account lockout, because a per-account
lockout would let an attacker lock victims out on purpose.

**What operators should do:** if your audience shares addresses, raise the visibility of
password hygiene; treat a lockout message to a member as a signal to check logs, not an
account problem.

## 8. admin/backup.php serves a database dump on a GET request

Behind the admin session, the backup panel streams a full dump (including the MD5
password hashes of limit 1) in response to a plain GET. `SameSite=Lax` still sends the
session cookie on top-level navigation, so a link planted where an admin clicks can
trigger a download into the admin's own browser.

**Why it is documented rather than fixed:** the attacker cannot read the downloaded
file; the practical risk is the file landing in a synced/scanned downloads folder. The
panel is the disaster-recovery path operators rely on, and this fork left its workflow
untouched. If your threat model cares, front the panel with HTTP auth at the server
level.

## 9. Cookies are not marked `secure` by default

The session and remember-me cookies are `HttpOnly` and `SameSite=Lax` (CHANGES item 43),
not `Secure`. Setting `Secure` unconditionally would log out every archive served over
plain HTTP, which still exists on private hosts and during migration windows.

**What operators should do:** serve the archive over HTTPS. On HTTPS-only deployments,
adding `session.cookie_secure=1` to the PHP configuration (or the server's PHP-FPM
pool) marks the session cookie without any application change.

## 10. Admin-installed code blocks execute PHP by design

eFiction's block and module system stores PHP in `fanfiction_codeblocks` and evaluates
it at page render. The writers of that table are the installer, the admin panels, and
module installation from files on disk (the full inventory is in CHANGES-MAINTENANCE.md,
"Eval and message_text inventory"). No member or visitor path writes it.

**Class:** not a defect — the admin account is the trust boundary, equivalent to
"may install software." Treat admin credentials and the `fanfiction_codeblocks` table
with the same care as shell access, and audit installed modules before running them.

## Reporting

Report security issues to Danilo Stern-Sapad via https://danilosapad.com/ (on behalf of
Hyperion360 Inc). Include the version line from `version.php` and, where possible, a
reproduction against a disposable install. Confirmed defects are recorded with their
reproduction in CHANGES-MAINTENANCE.md and repaired in the next release; limits like the
ones above are tracked here.
