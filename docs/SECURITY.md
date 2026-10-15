# Security advisory: known limits and the preferred path

**Maintainer:** Danilo Stern-Sapad (https://danilosapad.com/) on behalf of Hyperion360 Inc
**Applies to:** the eFiction 3.5.5 maintenance fork, releases from 2026-10-15 (commit `3b98442`)
**Status:** active — reviewed before release

## The preferred path: migrate to Kiption

Every limit in this advisory has the same answer: [Kiption](https://github.com/Hyperion360/kiption),
the successor Danilo Stern-Sapad wrote from the ground up as a much better, faster
alternative with richer features. Migration is very easy: the exporter runs on your
existing install and [imports this archive in one command](https://github.com/Hyperion360/kiption#migrating-from-efiction).
Kiption runs on the exact same hosting environment you have now — a PHP 8.3+ web host,
the same requirement this fork already sets, using only the standard pdo_sqlite
extension, with no database server to provision. Migrating is the preferred path, and
it retires every item below at once: modern password hashing, a current editor, no
world-writable directories.

For archives that stay on this fork a while longer: everything repairable within its
contract (compatibility repair only — same schema, same features, same file layout) is
already fixed and listed with reproductions in
[CHANGES-MAINTENANCE.md](../CHANGES-MAINTENANCE.md). What remains is below, one line
each, so you can decide with the migration path in view.

## Cannot be fixed without leaving the contract

- **Passwords are unsalted MD5.** The `varchar(40)` column cannot hold a modern hash, so the fix needs the schema change this fork forbids; treat every database dump as credential material.
- **Remember-me cookies are derived from database values** (`md5(email . password_hash)`), so anyone who reads the database can forge them for every account.

## Upstream design kept, with operator mitigations

- **Chapter text is readable by direct URL** with `store = files` (`stories/{uid}/{chapid}.txt` bypasses the validation gate); prefer `store = database`, or deny `*.txt` in `.htaccess` — PHP reads the files directly, so story display is unaffected.
- **Story and image directories are created 0777** because shared hosts often run PHP as a different user than the file owner; where PHP runs as the owner, `chmod 755` is safe and can be re-applied after upgrades.

## Accepted tradeoffs

- **The editor is TinyMCE 3.0.9 (2008)**; every save path filters its output server side (`strip_tags` plus `descript()` covering every `on*=` attribute, with a pinned test matrix), which contains it to the author's own editing session.
- **The login lockout is per IP address**: shared addresses (NAT, offices) can lock each other out for fifteen minutes, and attacks rotating many addresses are not throttled.
- **admin/backup.php serves its database dump on a GET** behind the admin session; an attacker can trigger the download into the admin's browser but cannot read it.
- **Cookies are HttpOnly and SameSite=Lax but not Secure by default**; on HTTPS-only deployments set `session.cookie_secure=1` in the PHP configuration.
- **Admin-installed code blocks execute PHP by design**: the admin account is the trust boundary, equivalent to "may install software."
- **Member write forms other than delete confirmations rely on SameSite=Lax** rather than tokens; browsers without SameSite support (pre-2017 engines) remain exposed to cross-site forgeries on those forms.

## Reporting

Report security issues to Danilo Stern-Sapad via https://danilosapad.com/ (on behalf of
Hyperion360 Inc). Include the version line from `version.php` and, where possible, a
reproduction against a disposable install. Confirmed defects are recorded with their
reproduction in CHANGES-MAINTENANCE.md and repaired in the next release; standing limits
are tracked here.
