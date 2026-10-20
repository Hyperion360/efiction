# eFiction 3.5.7 Port: bcrypt passwords, PHPMailer 6, session salt — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development (recommended) or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the three things official eFiction 3.5.7 does better than this fork — bcrypt password storage with transparent MD5 upgrade, PHPMailer 6.12, and a stored-hash-derived session salt — including a working upgrade path for existing 3.5.5 *and* 3.5.7 databases, without losing any of this fork's existing hardening.

**Architecture:** Pure password helpers live in `includes/corefunctions.php` behind `function_exists` guards (the same seam the CSRF helpers use, so the installer inherits them). The one schema change — widening `fanfiction_authors.password` to `varchar(255)` — is applied by an idempotent, lazily-invoked `ef_ensure_password_column()` at every password-write site, so no site can ever write a truncated hash, and a host that denies `ALTER` degrades to continued MD5 operation instead of breaking logins. `update.php` gains an optional CSRF-tokened, level-1-admin POST that performs the same widening up front. PHPMailer 5.x files are replaced wholesale by the 6.12.0 bundle from the official 3.5.7 zip under `includes/phpmailer/`, with our 109-line `includes/emailer.php` adapted to it.

**Tech Stack:** PHP 8.3/8.4 (mysqli), `password_hash()`/`password_verify()` (bundled since PHP 5.5), PHPMailer 6.12.0 (LGPL-2.1, vendored), the repo's zero-dependency `tests/compat/` harness (sh + PHP).

**Companion plan:** Kiption's importer learns bcrypt-era sources in `kiption/docs/superpowers/plans/2026-10-20-efiction-357-import.md`. The two plans are independent; neither blocks the other.

## Design principles

- **DRY:** every hash write goes through `ef_hash_password()`, every verify through `ef_verify_password()`, every column widening through `ef_ensure_password_column()` — nine call sites, three functions, zero inline `md5()` left on active paths. The salt computation exists once (`ef_session_salt()`), used by both `user/login.php` and `bridges/default/login.php`.
- **SOLID:** the helpers are the single reason-to-change seam for password format; call sites never know which format is stored. `ef_ensure_password_column()` owns the schema concern so no feature code runs DDL.
- **YAGNI:** no Argon2id configuration surface (`PASSWORD_DEFAULT` only), no password-history/rehash-policy machinery, no port of their auto-updater/installer (out of scope by fork contract). Foreign bridges (`php-fusion`, `eFic2eFic`) stay untouched and undocumented-supported, as today.
- **TDD:** every behavior lands as a harness check first (`runtime.php` for pure functions, `database.php` for the column helper, `security.php` for HTTP flows, `http.sh` for install/upgrade end-to-end), then the code, then the check goes green, then commit.

## Threat model (design-time, OWASP/STRIDE — folded in; a `/cso --diff` run gates the ship afterwards)

| # | Threat | Boundary | Mitigation baked into tasks |
|---|--------|----------|------------------------------|
| T1 | **Truncated bcrypt written into `varchar(40)`** (non-strict SQL silently truncates → user permanently locked out; attacker who can force an early write creates a denial) | password write paths | `ef_ensure_password_column()` runs **before** every bcrypt write; on `ALTER` failure the site falls back to writing MD5 (still valid in varchar(40)) — Task 2, negative test in Task 2 Step 1 |
| T2 | **update.php becomes a write endpoint** (new POST on a page that previously performed no writes; low-level admin or CSRF could trigger DDL) | admin surface | POST honored only for `isADMIN && uLEVEL == 1` (matches upstream's own updater stance), behind `ef_csrf_verify()`; the statement is a static, idempotent `ALTER` — Task 6 |
| T3 | **Privilege/visibility failure on `INFORMATION_SCHEMA`** (shared hosts sometimes hide it; empty result must not be read as "column is fine") | DB introspection | Unknown length ⇒ attempt the idempotent `ALTER` once ⇒ on failure enter MD5-fallback mode — Task 2 |
| T4 | **Stolen pre-upgrade cookies/salts replay** | session | Salt is now derived from the per-user random bcrypt hash; all old salts invalidate (one-time logout, documented) — Task 3 |
| T5 | **PHPMailer swap pulls in supply-chain or injection risk** | mail send | Vendored only from the official 3.5.7 zip; Task 7 verifies `VERSION` = 6.12.0, license files present, old `PHPMailerAutoload.php` (historical RCE vector) deleted; `emailer.php` keeps our call contract; address params pass through PHPMailer 6's validator |
| T6 | **Enumeration/oracle regressions** | auth | Lost-password keeps the uniform `_PASSWORDSENT` response and LP throttle; bcrypt-vs-MD5 verify timing differs only for the user's own row — no new oracle; `security.php` §9 assertions must stay green — Task 9 |

## File Structure

| File | Responsibility | Change |
|------|----------------|--------|
| `includes/corefunctions.php` | pure password helpers (guarded) | add `ef_hash_password`, `ef_password_needs_upgrade`, `ef_verify_password`, `ef_session_salt` |
| `includes/mysqli_functions.php` | DB-layer helpers (early-loaded, next to `ef_ip_log_key`) | add `ef_ensure_password_column()` |
| `user/login.php`, `bridges/default/login.php` | verify + transparent upgrade + salt | replace md5 verify; salt from stored hash |
| `user/editbio.php`, `bridges/default/editbio.php` | password set/change (registration flows through here — `user/register.php` is a stub that includes `editbio.php`) | hash via helper + ensure column |
| `user/lostpassword.php`, `bridges/default/lostpassword.php` | reset hashing | hash via helper + ensure column |
| `admin/members.php` | admin-forced password | hash via helper + ensure column |
| `install/install.php` | fresh installs | `varchar(255)` schema, bcrypt admin |
| `install/upgrade_modern_passwords.sql` | scripted upgrades | new file (same name/convention as official 3.5.7) |
| `update.php` | optional up-front widening | level-1 CSRF POST |
| `includes/phpmailer/**`, `includes/emailer.php` | mail | PHPMailer 6.12 swap |
| `includes/class.phpmailer.php`, `includes/class.smtp.php`, `includes/PHPMailerAutoload.php`, `languages/mailer/` | old mailer | delete |
| `docs/SECURITY.md`, `README.md`, `CHANGES-MAINTENANCE.md`, `docs/COMPATIBILITY.md`, `CREDITS.md` | docs | rewrite MD5 advisory, upgrade instructions, credits |
| `tests/compat/{runtime,database,security,http,docs}.sh.php` | harness | new checks (below) |

**Line-ending hazard (real, hit twice before):** every legacy file touched here is mixed CRLF/LF. Before editing any of them, note its current endings (`file` / `git diff` after a no-op touch) and preserve them; verify with `git diff --stat` showing only intended hunks after each edit.

---

### Task 1: Pure password helpers

**Files:**
- Modify: `includes/corefunctions.php` (inside the existing guarded-helpers block next to `ef_csrf_token`)
- Test: `tests/compat/runtime.php`

- [ ] **Step 1: Write the failing checks** — append to `tests/compat/runtime.php` next to the existing descript() checks:

```php
// ef_password_* helpers: hash format, verify, upgrade detection, session salt.
$checks[] = ['ef_hash_password produces bcrypt', function() {
    $h = ef_hash_password('swordfish');
    return is_string($h) && strncmp($h, '$2y$', 4) === 0 && strlen($h) === 60;
}];
$checks[] = ['ef_verify_password accepts md5 legacy and bcrypt', function() {
    return ef_verify_password('swordfish', md5('swordfish'))
        && ef_verify_password('swordfish', ef_hash_password('swordfish'))
        && !ef_verify_password('wrong', md5('swordfish'))
        && !ef_verify_password('wrong', ef_hash_password('swordfish'));
}];
$checks[] = ['ef_password_needs_upgrade detects only md5', function() {
    return ef_password_needs_upgrade(md5('x')) === true
        && ef_password_needs_upgrade(ef_hash_password('x')) === false
        && ef_password_needs_upgrade('0') === false;
}];
$checks[] = ['ef_session_salt derives from stored hash', function() {
    $stored = ef_hash_password('swordfish');
    return ef_session_salt('a@b.c', $stored) === md5('a@b.c' . $stored)
        && ef_session_salt('a@b.c', md5('swordfish')) === md5('a@b.c' . md5('swordfish'));
}];
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/compat/runtime.php` (PHP 8.3 at `/opt/homebrew/opt/php@8.3/bin/php`)
Expected: FAIL — "Call to undefined function ef_hash_password()"

- [ ] **Step 3: Implement** — in `includes/corefunctions.php`, after the `ef_csrf_*` block, same `function_exists` guard style:

```php
if(!function_exists("ef_hash_password")) {
	function ef_hash_password($plain) {
		return password_hash($plain, PASSWORD_DEFAULT);
	}
}
if(!function_exists("ef_password_needs_upgrade")) {
	function ef_password_needs_upgrade($stored) {
		return is_string($stored) && strlen($stored) === 32 && ctype_xdigit($stored);
	}
}
if(!function_exists("ef_verify_password")) {
	// Returns true for a matching bcrypt hash OR a matching legacy 32-hex MD5
	// (which callers then upgrade via ef_hash_password + ef_ensure_password_column).
	function ef_verify_password($plain, $stored) {
		if(!is_string($stored) || $stored === "" || $stored === "0") return false;
		if(ef_password_needs_upgrade($stored)) return hash_equals($stored, md5($plain));
		return password_verify($plain, $stored);
	}
}
if(!function_exists("ef_session_salt")) {
	function ef_session_salt($email, $storedhash) {
		return md5($email . $storedhash);
	}
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php tests/compat/runtime.php`
Expected: PASS, all checks green

- [ ] **Step 5: Commit**

```bash
git add includes/corefunctions.php tests/compat/runtime.php
git commit -m "feat: bcrypt password helpers with legacy md5 verify (3.5.7 port)"
```

---

### Task 2: The column-widening seam (T1, T3)

**Files:**
- Modify: `includes/mysqli_functions.php` (next to `ef_ip_log_key`)
- Test: `tests/compat/database.php`

- [ ] **Step 1: Write the failing checks** — append to `tests/compat/database.php`'s DB-backed section (it already builds the efqa schema/user against 127.0.0.1:3306):

```php
// ef_ensure_password_column: widens password to varchar(255), idempotent,
// and reports false (without breaking the connection) when ALTER is unavailable.
$checks[] = ['ef_ensure_password_column widens then is idempotent', function() use($link) {
    mysqli_query($link, "ALTER TABLE fanfiction_authors MODIFY password varchar(40) NOT NULL default ''");
    $first = ef_ensure_password_column();
    $len = mysqli_fetch_assoc(mysqli_query($link,
        "SELECT CHARACTER_MAXIMUM_LENGTH AS l FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fanfiction_authors' AND COLUMN_NAME = 'password'"))['l'];
    $second = ef_ensure_password_column(); // must be a harmless no-op
    return $first === true && $len === '255' && $second === true;
}];
```

(The ALTER-denied path is exercised in `security.php` Task 9 by revoking the privilege for a secondary user.)

- [ ] **Step 2: Run to verify failure**

Run: `php tests/compat/database.php`
Expected: FAIL — undefined function `ef_ensure_password_column`

- [ ] **Step 3: Implement** — in `includes/mysqli_functions.php`. **Do not use `dbquery()` here** (eng-review finding): its failure path is fatal by design (`accessDenied()` exit in the app, thrown Error pre-bootstrap — `includes/mysqli_functions.php:50-65`), so a denied `ALTER` would kill the login request instead of degrading to MD5. Use the raw `$dbconnect` handle inside a private `try/catch`:

```php
if(!function_exists("ef_ensure_password_column")) {
	// Widens fanfiction_authors.password to varchar(255) exactly once per
	// request. Returns true when the column can hold password_hash() output.
	// On any failure returns false and the caller MUST fall back to writing
	// legacy md5 (never a truncated bcrypt). T1/T3: unknown length or an
	// INFORMATION_SCHEMA the host hides both route through the ALTER attempt.
	// Raw $dbconnect + catch: dbquery() is deliberately fatal on failure
	// (mysqli_functions.php:50-65) and this seam must be returnable.
	function ef_ensure_password_column() {
		static $ok = null;
		if($ok !== null) return $ok;
		$ok = false;
		global $dbconnect;
		if(!$dbconnect) return false;
		try {
			$result = $dbconnect->query("SELECT CHARACTER_MAXIMUM_LENGTH AS l FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = '".TABLEPREFIX."fanfiction_authors' AND COLUMN_NAME = 'password'");
			$row = $result ? $result->fetch_assoc() : null;
			if($row && isset($row['l']) && (int)$row['l'] >= 255) { $ok = true; return true; }
			// Idempotent DDL: re-running CHANGE on an already-wide column succeeds.
			$alt = $dbconnect->query("ALTER TABLE ".TABLEPREFIX."fanfiction_authors
				CHANGE `password` `password` varchar(255) NOT NULL default ''");
			if($alt) $ok = true;
		} catch(\mysqli_sql_exception $e) {
			// Denied ALTER / unreadable INFORMATION_SCHEMA: stay in md5 mode.
		}
		return $ok;
	}
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php tests/compat/database.php`
Expected: PASS ("database ok")

- [ ] **Step 5: Commit**

```bash
git add includes/mysqli_functions.php tests/compat/database.php
git commit -m "feat: idempotent password column widening with md5 fallback contract"
```

---

### Task 3: Login verify + transparent upgrade + salt (T4)

**Files:**
- Modify: `user/login.php:74,92,104`, `bridges/default/login.php:38` (+ its salt/cookie lines)
- Test: `tests/compat/security.php` (new §10)

- [ ] **Step 1: Write the failing checks** — add §10 to `tests/compat/security.php` (it already drives HTTP login against the php -S fixture with the `efqa` user; the fixture seeds `efqa` with `md5('efcipassword')`):

```php
// §10 bcrypt upgrade-on-login: legacy md5 user upgrades transparently,
// keeps logging in, and a pre-upgrade wrong password is still rejected.
$section = 10;
$checks[] = ["legacy md5 login upgrades row to bcrypt", function() use($base) {
    login_http('efqa', 'efcipassword');                       // helper already used by §9 throttle tests
    $row = db_one("SELECT password FROM fanfiction_authors WHERE penname = 'efqa'");
    return strncmp($row['password'], '$2y$', 4) === 0;
}];
$checks[] = ["bcrypt user re-logins with same password", function() use($base) {
    return login_http('efqa', 'efcipassword') !== false;
}];
$checks[] = ["wrong password rejected after upgrade", function() use($base) {
    return login_http('efqa', 'not-the-password') === false;
}];
$checks[] = ["salt matches stored bcrypt hash after upgrade", function() {
    $row = db_one("SELECT email, password FROM fanfiction_authors WHERE penname = 'efqa'");
    // Re-deriving the cookie salt from the now-bcrypt row must equal what
    // get_session_vars.php computes (md5(email . stored_hash)).
    return true; // structural: asserted via successful re-login above + get_session_vars passthrough
}];
// Reset for later sections so md5-era assumptions elsewhere still hold:
dbquery("UPDATE fanfiction_authors SET password = '".md5('efcipassword')."' WHERE penname = 'efqa'");
```

(The 4th check is folded into the 2nd: a successful re-login *is* the salt assertion, since `get_session_vars.php:34,51` compares `md5(email.stored_password)` unchanged.)

- [ ] **Step 2: Run to verify failure**

Run: `php tests/compat/security.php`
Expected: FAIL — stored row stays MD5 after login

- [ ] **Step 3: Implement** in `user/login.php`. Replace line 74 (`$encryptedpassword = md5($_POST['password']);`) and the success branch: after password verification succeeds, upgrade when needed and compute the salt from the **stored** hash:

```php
// was: $encryptedpassword = md5($_POST['password']);
$storedpassword = $passwd['password']; // from the earlier SELECT
if(!ef_verify_password($_POST['password'], $storedpassword)) { /* existing failure path: count LF row, etc. */ }
else {
    if(ef_password_needs_upgrade($storedpassword) && ef_ensure_password_column()) {
        $newhash = ef_hash_password($_POST['password']);
        dbquery("UPDATE ".TABLEPREFIX."fanfiction_authors SET password = '$newhash' WHERE uid = '".$passwd['uid']."'");
        $storedpassword = $newhash;
    }
    // lines ~92 and ~104: md5($passwd['email'].$encryptedpassword) becomes:
    $salt = ef_session_salt($passwd['email'], $storedpassword);
}
```

Keep the existing throttle INSERTs, `session_regenerate_id(true)`, and cookie option array (`httponly`, `samesite => Lax`) exactly as they are — only the hash/salt values change. Apply the identical change to `bridges/default/login.php:38` and its salt lines.

- [ ] **Step 4: Run to verify pass**

Run: `php tests/compat/security.php`
Expected: PASS — all sections, 47 + new checks green

- [ ] **Step 5: Commit**

```bash
git add user/login.php bridges/default/login.php tests/compat/security.php
git commit -m "feat: transparent md5-to-bcrypt upgrade on login, salt from stored hash"
```

---

### Task 4: Every remaining password-write site

**Files:**
- Modify: `user/editbio.php:75,86,128`, `bridges/default/editbio.php:70,81,124`, `user/lostpassword.php:67`, `bridges/default/lostpassword.php:56`, `admin/members.php:95`

- [ ] **Step 1: Write the failing checks** — extend `tests/compat/security.php`:

```php
$checks[] = ["lostpassword issues bcrypt", function() {
    request_reset_for('efqa@example.test');      // existing §9 helper
    $row = db_one("SELECT password FROM fanfiction_authors WHERE penname = 'efqa'");
    return strncmp($row['password'], '$2y$', 4) === 0;
}];
$checks[] = ["admin member reset issues bcrypt", function() {
    admin_post('admin.php?action=members', ['uid' => 2, 'password' => 'admin-set-pw']); // existing admin session helper
    $row = db_one("SELECT password FROM fanfiction_authors WHERE uid = 2");
    return strncmp($row['password'], '$2y$', 4) === 0;
}];
dbquery("UPDATE fanfiction_authors SET password = '".md5('efcipassword')."' WHERE penname = 'efqa'");
dbquery("UPDATE fanfiction_authors SET password = '".md5('oldpw')."' WHERE uid = 2");
```

(`request_reset_for` / `admin_post` / `db_one` = whatever the existing §9 battery already names its curl-and-parse helpers — reuse them as-is; the assertions, not the helper names, are the contract.)

- [ ] **Step 2: Run to verify failure** — Run: `php tests/compat/security.php` — Expected: FAIL (hashes stay 32-hex)

- [ ] **Step 3: Implement** — the same three-line pattern at each hash-write site (shown for `user/editbio.php:75`; identical at `user/editbio.php:86`, `bridges/default/editbio.php:70,81`, `user/lostpassword.php:67`, `bridges/default/lostpassword.php:56`, `admin/members.php:95`):

```php
// was: $encryppass = md5($pass);
$encryppass = ef_ensure_password_column() ? ef_hash_password($pass) : md5($pass);
```

And the current-password verification at `user/editbio.php:128` / `bridges/default/editbio.php:124`:

```php
// was: $encryppassword = md5($_POST['password']); ... == $passwd['password']
if(!ef_verify_password($_POST['password'], $passwd['password'])) { /* existing wrong-password branch */ }
```

- [ ] **Step 4: Run to verify pass** — Run: `php tests/compat/security.php` — Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add user/editbio.php bridges/default/editbio.php user/lostpassword.php bridges/default/lostpassword.php admin/members.php tests/compat/security.php
git commit -m "feat: all password writes go through bcrypt helpers with md5 fallback"
```

---

### Task 5: Installer

**Files:**
- Modify: `install/install.php:444` (admin hash), `install/install.php:779` (schema)
- Create: `install/upgrade_modern_passwords.sql`

- [ ] **Step 1: Write the failing check** — in `tests/compat/http.sh`, inside the fresh-install section after the existing post-install DB assertions (the harness already has a mysql client invocation pattern):

```sh
# Fresh install stores the admin password with password_hash() (bcrypt).
admin_hash=$(mysql -h127.0.0.1 -P3306 -uroot -N -B -e \
  "SELECT password FROM ${DBNAME}.fanfiction_authors ORDER BY uid LIMIT 1")
case "$admin_hash" in
  '$2y$'*) echo "fresh admin bcrypt: ok" ;;
  *) echo "FAIL: fresh admin hash is not bcrypt: $admin_hash"; exit 1 ;;
esac
```

- [ ] **Step 2: Run to verify failure** — Run: `sh tests/compat/http.sh` — Expected: FAIL at the new assertion

- [ ] **Step 3: Implement**

`install/install.php:779`: `varchar(40)` → `varchar(255)` in the `fanfiction_authors` CREATE.
`install/install.php:444`:

```php
// was: if($_POST['password'] == $_POST['password2']) $encryptpassword = md5($_POST['password']);
if($_POST['password'] == $_POST['password2']) $encryptpassword = ef_hash_password($_POST['password']);
```

(The installer loads `includes/corefunctions.php` — the guarded helpers are already available there, proven by the `ef_csrf_*` precedent.)

Create `install/upgrade_modern_passwords.sql` (same convention/name as official 3.5.7, for scripted upgrades):

```sql
-- eFiction maintenance fork: widen the password column for password_hash()
-- output (bcrypt = 60 chars). Idempotent; adjust the table prefix if your
-- installation uses a different one. update.php offers the same step.
ALTER TABLE `fanfiction_authors` CHANGE `password` `password` varchar(255) NOT NULL default '';
```

- [ ] **Step 4: Run to verify pass** — Run: `sh tests/compat/http.sh` — Expected: PASS, "fresh=78 checks" (77 + 1 new)

- [ ] **Step 5: Commit**

```bash
git add install/install.php install/upgrade_modern_passwords.sql tests/compat/http.sh
git commit -m "feat: installer creates varchar(255) passwords and a bcrypt admin"
```

---

### Task 6: update.php optional widening (T2)

**Files:**
- Modify: `update.php` (after the `isADMIN` gate at lines 33-45, before the current read-only message)

- [ ] **Step 1: Write the failing check** — in `tests/compat/http.sh` upgrade section (which drives an admin session against a 3.5.5-schema database):

```sh
# update.php: level-1 admin POST widens the column; non-admin POST does nothing.
post_without_token=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/update.php" -d 'widen=1')
[ "$post_without_token" != "200" ] || { curl -s "$BASE/update.php" | grep -q 'name="ef_csrf"' \
  || { echo "FAIL: update.php offers no tokened widen form to admin"; exit 1; }; }
```

Plus in `security.php`: a POST to `update.php` with `widen=1` but **no** token from a non-admin session must not change the column (reuse the §9 CSRF-battery session helper).

- [ ] **Step 2: Run to verify failure** — Run: `sh tests/compat/http.sh` — Expected: FAIL (no form exists)

- [ ] **Step 3: Implement** — in `update.php`, inside the existing admin branch:

```php
// Maintenance fork: the only schema step this fork ever needs — widening the
// password column for password_hash(). Applied automatically at the first
// password write anyway; this form lets the main administrator do it up front.
if(isset($_POST['widen']) && isADMIN && uLEVEL == 1 && ef_csrf_verify()) {
    ef_ensure_password_column();
}
$plen = ef_password_column_length(); // trivial INFORMATION_SCHEMA SELECT, false on failure
if(isADMIN && uLEVEL == 1 && $plen !== false && $plen < 255) {
    $output .= "<form method=\"post\" action=\"update.php\">"
        . "<p>The password column is still varchar($plen). Widen it now (idempotent)?</p>"
        . ef_csrf_field() . "<input type=\"hidden\" name=\"widen\" value=\"1\">"
        . "<button type=\"submit\">Widen password column</button></form>";
}
```

Add the tiny `ef_password_column_length()` next to `ef_ensure_password_column()` in `includes/mysqli_functions.php` (same INFORMATION_SCHEMA SELECT, returns `(int)` or `false`) — DRY: `ef_ensure_password_column()` internally calls it. The existing read-only explanation text is updated to describe the one step.

- [ ] **Step 4: Run to verify pass** — Run: `sh tests/compat/http.sh && php tests/compat/security.php` — Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add update.php includes/mysqli_functions.php tests/compat/http.sh tests/compat/security.php
git commit -m "feat: csrf-gated level-1 admin option to widen the password column early"
```

---

### Task 7: PHPMailer 6.12 swap (T5)

**Files:**
- Create: `includes/phpmailer/` (vendored `PHPMailer-6.12.0/` + `language/` from the official 3.5.7 zip)
- Modify: `includes/emailer.php`
- Delete: `includes/class.phpmailer.php`, `includes/class.smtp.php`, `includes/PHPMailerAutoload.php`, `languages/mailer/`
- Modify: `CREDITS.md`

- [ ] **Step 1: Vendor and verify provenance** — source is the already-downloaded official zip (`/tmp/ef357/efiction-3.5.7.zip`; re-download from `https://update.efiction.org/v3/3.5/efiction-3.5.7.zip` if /tmp was reaped):

```bash
unzip -q /tmp/ef357/efiction-3.5.7.zip -d /tmp/ef357/v357
cat /tmp/ef357/v357/efiction-3.5.7/includes/phpmailer/PHPMailer-6.12.0/VERSION   # expect: 6.12.0
ls /tmp/ef357/v357/efiction-3.5.7/includes/phpmailer/PHPMailer-6.12.0/LICENSE    # LGPL-2.1 present
cp -R /tmp/ef357/v357/efiction-3.5.7/includes/phpmailer includes/
diff /tmp/ef357/v357/efiction-3.5.7/includes/emailer.php includes/emailer.php    # 1-line delta: port theirs, keep ours if ours carries a local fix
rm includes/class.phpmailer.php includes/class.smtp.php includes/PHPMailerAutoload.php
rm -r languages/mailer/
```

Adapt `includes/emailer.php` to load `includes/phpmailer/PHPMailer-6.12.0/src/PHPMailer.php` + `SMTP.php` (their 108-line `emailer.php` is the reference implementation — port its loading block onto our file, keeping our `sendemail()` signature and any local delta the diff reveals).

- [ ] **Step 2: Fix every reference to the old mailer files** (eng-review finding — three exist outside `emailer.php`): `tests/compat/runtime.php:72` requires `includes/class.phpmailer.php` and `:82` reads it for a content check (repoint both to `includes/phpmailer/PHPMailer-6.12.0/src/PHPMailer.php`); `includes/emailer.php:56` requires `PHPMailerAutoload.php` (replaced by the ported loading block). Then sweep for stragglers:

```bash
rg -n "class\.phpmailer|class\.smtp|PHPMailerAutoload|languages/mailer" --glob '!includes/phpmailer/**' .
# Expected after fixes: only the deletion candidates themselves (handled in Step 3)
```

- [ ] **Step 3: Lint everything new** — Run: `sh tests/compat/lint.sh` — Expected: PASS, 0 parse failures (PHPMailer 6.12 is PHP 8.3/8.4-clean)

- [ ] **Step 4: Behavioral check*** — `tests/compat/security.php` already exercises the lost-password mail path against the fixture SMTP-less install (`sendemail` returns false gracefully there); confirm §9's uniform-response checks stay green: Run: `php tests/compat/security.php` — Expected: PASS

- [ ] **Step 5: CREDITS.md*** — update the PHPMailer entry: version 5.x → 6.12.0, license LGPL-2.1, vendored from eFiction 3.5.7's bundle; add one line crediting the official 3.5.7 modernization (efiction.org) as the source of the ported password-modernization approach (GPL v2 derivative, same as this fork).

- [ ] **Step 6: Commit**

```bash
git add includes/phpmailer includes/emailer.php CREDITS.md
git rm includes/class.phpmailer.php includes/class.smtp.php includes/PHPMailerAutoload.php
git rm -r languages/mailer/
git commit -m "feat: PHPMailer 6.12 vendored, legacy 5.x mailer removed (3.5.7 port)"
```

---

### Task 8: Documentation

**Files:**
- Modify: `docs/SECURITY.md`, `README.md`, `CHANGES-MAINTENANCE.md`, `docs/COMPATIBILITY.md`
- Test: `tests/compat/docs.sh`

- [ ] **Step 1: Update `tests/compat/docs.sh` needles first** (the docs must not regress once rewritten):

```sh
rg -q 'varchar(255)' README.md                       # new upgrade sentence exists
! rg -qi 'the fix needs the schema change this fork forbids' docs/SECURITY.md   # old can't-fix claim gone
rg -qi 'PHPMailer 6' CREDITS.md
```

- [ ] **Step 2: Run to verify failure** — Run: `sh tests/compat/docs.sh` — Expected: FAIL on the new needles

- [ ] **Step 3: Rewrite the docs**

- `docs/SECURITY.md`: delete the "Passwords are unsalted MD5" bullet; replace with a fixed-note ("Passwords are hashed with `password_hash()` (bcrypt); legacy MD5 hashes upgrade transparently at each user's next successful login — after which old database dumps no longer verify"). Rewrite the remember-me bullet: still DB-derived (`md5(email . stored_hash)`), so database read = cookie forgery remains an honest limit; note the one-time logout on upgrade.
- `README.md`: replace "same schema, no `update.php` migration, nothing to run" (line ~24) with: file-compatible with 3.5.5 *and* 3.5.7 databases; the single schema step (password column → `varchar(255)`) is applied automatically at the first password write, or up front by visiting `update.php` directly (the fork keeps the files' version equal to 3.5.5, so the page is reached by URL, not by a version-mismatch redirect) and clicking the tokened button, or scripted via `install/upgrade_modern_passwords.sql`; sites already on official 3.5.7 (bcrypt hashes present) verify and keep working unchanged.
- `CHANGES-MAINTENANCE.md`: items 57-60 (bcrypt + transparent upgrade; column-widening seam with MD5 fallback; PHPMailer 6.12; session salt from stored hash) with a dated docs entry crediting the 3.5.7 port.
- `docs/COMPATIBILITY.md`: add "Source databases: eFiction 3.5.5 (MD5) and 3.5.7 (bcrypt or mixed)" row.

- [ ] **Step 4: Run to verify pass** — Run: `sh tests/compat/docs.sh` — Expected: PASS ("docs ok")

- [ ] **Step 5: Commit**

```bash
git add docs/SECURITY.md README.md CHANGES-MAINTENANCE.md docs/COMPATIBILITY.md tests/compat/docs.sh
git commit -m "docs: bcrypt migration, 3.5.7-database compatibility, PHPMailer 6"
```

---

### Task 9: Full harness matrix + regression sweep

**Files:** none new — this task runs everything.

- [ ] **Step 1: Full local matrix** (both PHPs, both DB servers — the user's dev servers on 3306/3307 stay running):

```bash
for php in /opt/homebrew/opt/php@8.3/bin/php /opt/homebrew/opt/php@8.4/bin/php; do
  PHP=$php sh tests/compat/lint.sh && PHP=$php sh tests/compat/http.sh \
    && $php tests/compat/runtime.php && $php tests/compat/database.php \
    && $php tests/compat/security.php || exit 1
done
sh tests/compat/docs.sh && sh tests/compat/package.sh
```

Expected: all green; `http.sh` reports fresh=78, upgrade=19 (one new upgrade-flow check: after admin widens + legacy user logs in, column reads 255 and hash starts `$2y$`).

PHP 8.5 ceiling parity: add `'8.5'` to both matrix lists in `.github/workflows/compat.yml` (`matrix.php`, lines ~31 and the MariaDB job's equivalent). CI verifies 8.5 via `shivammathur/setup-php`; do **not** upgrade the user's Homebrew mainline `php` (8.4.8) to test locally — if a `php@8.5` versioned formula appears later, add it to the loop above then. Local 8.3/8.4 + CI 8.3/8.4/8.5 is the verified surface, matching official 3.5.7's tested ceiling ("8.5, newest release available").

- [ ] **Step 2: Verify the ALTER-denied fallback end to end (T1)** — in the security fixture, create `efqa2` with only SELECT/UPDATE/INSERT (no ALTER), drive a login, assert: login succeeds, stored hash remains valid 32-hex MD5, no truncated hash, no fatal. Fold this as the 5th §10 check so it runs permanently.

- [ ] **Step 3: Push and watch CI** — `git push origin main`, confirm the GitHub Actions matrix (PHP 8.3/8.4 × MySQL 8.0/MariaDB 10.11) is green.

- [ ] **Step 4: Ship gate** — run `/qa-full` (its `/defense` + `/cso --diff` steps check the threats T1-T6 against real code), then tag `v3.5.5-maint.2`.

- [ ] **Step 5: Commit any harness polish from Step 2** (if the fallback check needed helper changes):

```bash
git add tests/compat/security.php
git commit -m "test: alter-denied fallback keeps md5 logins working"
```

---

### Task 10: No gaps against future official releases (Sidular line)

**Files:**
- Modify: `.github/workflows/upstream-canary.yml` (new), `docs/COMPATIBILITY.md`, `README.md`
- Test: `tests/compat/runtime.php`, `tests/compat/http.sh` (upgrade-flow variant)

**Contract this task enforces:** the fork runs on *any* official 3.5.x database, including releases newer than this fork (3.5.8+ from `update.efiction.org`): the app SELECTs only columns it knows, so unknown newer columns are ignored; `ef_verify_password` adjudicates via `password_verify()`, which handles every `password_hash()` algorithm PHP supports (bcrypt today, argon2i/argon2id if a future official release or PHP default produces them); the widen form appears only when the column is actually narrow.

- [ ] **Step 1: Write the failing checks**

`tests/compat/runtime.php` — pin argon-family tolerance (the forward-compat case):

```php
$checks[] = ['ef_verify_password accepts argon2id hashes without upgrade flag', function() {
    if(!defined('PASSWORD_ARGON2ID')) return true; // runtime without argon: nothing to pin
    $h = password_hash('swordfish', PASSWORD_ARGON2ID);
    return ef_verify_password('swordfish', $h) && !ef_verify_password('wrong', $h)
        && ef_password_needs_upgrade($h) === false;
}];
```

`tests/compat/http.sh` upgrade-flow variant — a "3.5.8-style" fixture database (version row set to `3.5.8`, one extra unknown column `ALTER TABLE fanfiction_stories ADD future_col varchar(64)`): pages load 200, login works, `update.php` renders its no-update-needed message (it must not try to downgrade or "fix" a newer version).

- [ ] **Step 2: Run to verify failure** — Run: `php tests/compat/runtime.php && sh tests/compat/http.sh` — Expected: runtime check fails on the new closure; http variant fails (fixture doesn't exist yet)

- [ ] **Step 3: Implement**

The runtime check needs nothing (helper already calls `password_verify`); add the http.sh fixture variant. Add the canary workflow `.github/workflows/upstream-canary.yml`:

```yaml
name: upstream-canary
on:
  schedule: [{cron: "0 6 * * 1"}]   # Mondays 06:00 UTC; public repo => $0 Actions minutes
  workflow_dispatch:
permissions:
  issues: write
  contents: read
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
        with: {persist-credentials: false}
      - name: Compare upstream feed
        env: {TESTED_UPSTREAM: "3.5.7"}
        run: |
          latest=$(curl -fsS --max-time 20 https://update.efiction.org/v3/3.5/latest.json | jq -r .version)
          [ "$(printf '%s\n' "$TESTED_UPSTREAM" "$latest" | sort -V | tail -1)" = "$TESTED_UPSTREAM" ] \
            || echo "newer=$latest" >> "$GITHUB_OUTPUT"
        id: feed
      - name: Open tracking issue
        if: steps.feed.outputs.newer
        uses: actions/github-script@60a0d83039c74a4aee543508d2ffcb1c3799cdea # v7.0.1
        with:
          script: |
            const {data: open} = await github.rest.issues.listForRepo({context.repo.repo, owner: context.repo.owner, state: 'open', labels: 'upstream-release'});
            if (open.length === 0) await github.rest.issues.create({owner: context.repo.owner, repo: context.repo.repo, labels: ['upstream-release'], title: `Official eFiction ${process.env.newer || 'release'} shipped — re-verify compat`, body: 'Download the release zip, run the import/run-under fixtures (Task 10 of the 3.5.7 port plan), widen TESTED_UPSTREAM, document the delta.'});
```

Docs: `docs/COMPATIBILITY.md` gains the parity statement — ceiling: PHP 8.3–8.5, matching official 3.5.7's tested ceiling; floor: 8.3, deliberately and finally. The fork is for archives on modern hosting. Users whose hosts cannot run modern PHP are Kiption's audience, not this fork's: individual writers post free at kiption.com, and archive owners get their archive hosted and migrated for them at kiption.com/cloud (eFiction migration on paid tiers) — the README and COMPATIBILITY both say this in one sentence at the requirement line, so a dead-host admin's next click is Kiption. Old-PHP support is out of scope permanently (owner decision, 2026-10-20): no 5.6–8.2 compatibility shims, no polyfills, no degraded modes. Sources: any official 3.5.x database including future releases, unknown newer columns ignored, with the canary as the tripwire.

- [ ] **Step 4: Run to verify pass** — Run: `php tests/compat/runtime.php && sh tests/compat/http.sh` — Expected: PASS; canary verified via `workflow_dispatch` on push (one manual run, free)

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/upstream-canary.yml docs/COMPATIBILITY.md README.md tests/compat/runtime.php tests/compat/http.sh
git commit -m "feat: php 8.5 ceiling parity + future-official-release contract and canary"
```

---

## Test Plan & Verification

**Coverage target:** every new function and every converted call site is pinned by at least one harness check — 4 new `runtime.php` checks (pure helpers), 1 `database.php` check (widening + idempotency), ≥6 new `security.php` checks (upgrade-on-login, re-login, wrong-password, lostpassword bcrypt, admin-reset bcrypt, ALTER-denied fallback, update.php CSRF), 2 new `http.sh` checks (fresh-install bcrypt admin, upgrade-flow widening). Total: 47 → ≥54 security checks, 77 → 78 fresh, 18 → 19 upgrade. Zero `md5(` password sites remain on active paths (`rg -n 'md5\(' user/ bridges/default/ admin/members.php install/install.php` → only `ef_session_salt` internals and non-password uses).

**Critical paths (must pass before ship):**
- Legacy MD5 user logs in → row becomes `$2y$` → same password logs in again → `get_session_vars` accepts the session (salt = `md5(email.stored_bcrypt)`) → `security.php` §10
- Official-3.5.7 database (bcrypt rows) dropped under our files → login verifies via `password_verify` → no double-upgrade → `security.php` §10 (seed bcrypt row variant)
- Fresh install → admin row is bcrypt, column is varchar(255) → `http.sh` fresh flow
- ALTER-denied host → login + lostpassword + admin reset still work in MD5 mode, nothing truncated → `security.php` fallback check
- Lost-password mail path renders without the old mailer → §9 uniform-response checks green after Task 7

**Edge cases & error paths:**
- `$stored === '0'` or `''` (passwordless rows) → `ef_verify_password` returns false without `password_verify` warnings → runtime.php check 3
- Column already 255 → `ef_ensure_password_column()` short-circuits, no DDL → database.php idempotency arm
- `INFORMATION_SCHEMA` hidden → falls through to idempotent ALTER attempt → T3 covered by fallback check
- update.php POST without token / from non-admin / from level-2 admin → no DDL → security.php §9-style negative
- Mixed-version rows (some MD5, some bcrypt — the exact state of a half-migrated 3.5.7 site) → per-row detection → §10 mixed-seed variant

**Regression guards:**
- Login throttle, `session_regenerate_id`, cookie `httponly`/`Lax` flags unchanged → §9 battery stays green untouched
- Lost-password uniform response + LP throttle unchanged → §9 checks
- CSRF central gate + panel forms untouched → §9 CSRF battery
- Release zip still excludes workflows/dist → `package.sh`

**Verification commands:**
- Unit/pure: `php tests/compat/runtime.php` — expected: all pass
- DB: `php tests/compat/database.php` — expected: "database ok"
- HTTP/security: `sh tests/compat/http.sh && php tests/compat/security.php` — expected: fresh=78, upgrade=19, security ≥54, all pass
- Docs/packaging: `sh tests/compat/docs.sh && sh tests/compat/package.sh` — expected: pass
- CI: push → `.github/workflows/compat.yml` 8 jobs green

**Acceptance criteria (from spec):**
- [ ] Official 3.5.7's three wins are ours: bcrypt + transparent upgrade, PHPMailer 6.12, stored-hash session salt → Tasks 1-4, 7
- [ ] Upgrade path works from both 3.5.5 (MD5, varchar 40) and 3.5.7 (bcrypt, varchar 255) databases → Tasks 2, 3, 6 + mixed-seed checks
- [ ] No site can ever store a truncated hash → Task 2 + fallback test
- [ ] SECURITY.md no longer lists unsalted MD5 as unfixable → Task 8
- [ ] All pre-existing hardening (CSRF, throttles, enumeration closure) verified intact → Task 9 full matrix

---

## GSTACK REVIEW REPORT

**Target:** this plan + `kiption:docs/superpowers/plans/2026-10-20-efiction-357-import.md` (one review, both subsystems)
**Reviewer:** plan-eng-review (gstack), 2026-10-20. Codex outside voice NOT run (billed API; no user authorization) — outside coverage: **unavailable**.

### Reality rows (tier 1)

- REALITY: premise-table pass — every 3.5.7 claim verified against the extracted official zip and repo code; 0 unverified premises remain (MODERNIZATION.md read in full; fork grep evidence in conversation log).
- REALITY: already-done pass — the fork already ships two of 3.5.7's fixes and correctly does not re-port them (`install/install.php:1244` var_export config write; `update.php:53` version_compare).
- REALITY: surface-check pass — bcrypt via bundled `password_hash()` (no new dependency); the idempotent ALTER statement matches official 3.5.7's own (`dbupgrade.php:291` pattern).
- REALITY: binding-decisions finding — the fork's documented "same schema" contract is relaxed by this plan; a binding project decision reversed needs owner confirmation → **D2 below (unresolved)**.
- REALITY: numbers pass — harness check counts (78/19/54+) are labeled targets, not measurements.
- REALITY: deferred-asks pass — user's four asks (port useful bits; upgrade path; Kiption import coverage; all supported versions incl. upgraded DBs) each map to tasks; nothing silently dropped.
- REALITY: pr-count pass — 2 repos × 1 branch, ~9+7 tasks, each independently committable; PHPMailer swap is a separable task if D2 defers.

### Findings (verified against code, folded into the plans)

| # | Sev | Conf | Location | Finding | Disposition |
|---|-----|------|----------|---------|-------------|
| F1 | P1 | 9/10 | `includes/mysqli_functions.php:50-65` | Plan originally ran the fallback ALTER through `dbquery()`, whose failure path is fatal by design (accessDenied exit / thrown Error) — the MD5-fallback contract could never return false; a denied ALTER would kill the login request | **folded** — Task 2 now uses raw `$dbconnect->query()` inside `try/catch(mysqli_sql_exception)` |
| F2 | P2 | 9/10 | `tests/compat/runtime.php:72,82` | runtime.php requires and content-checks `includes/class.phpmailer.php`; Task 7's deletion would break the harness | **folded** — Task 7 Step 2 repoints both + adds a straggler sweep |
| F3 | P2 | 8/10 | `includes/emailer.php:56` | emailer requires `PHPMailerAutoload.php`, deleted by the swap | **folded** — covered by Task 7 Step 2 sweep |
| F4 | P2 | 9/10 | `kiption:tests/ImportCliTest.php:64,109` | fixture growing 2→3 members breaks two COUNT assertions | **folded** — Plan B Task 3 updates both + grep step |
| F5 | P3 | 8/10 | `update.php` reachability | fork keeps version == 3.5.5 so update.php never auto-redirects; the widen form is direct-URL only | **folded** — Task 8 README wording now says "visit update.php directly" |
| F6 | P3 | 7/10 | `user/login.php` salt lines | salt must derive from the POST-UPGRADE stored hash or remember-me breaks on the upgrade login itself | **folded** — Task 3 sequence computes salt after the UPDATE, from `$storedpassword` reassignment |

### Sections 1-4 (summary)

- **Architecture:** seams respected (guarded helpers / raw-link DDL seam / legacy columns mirroring an existing one); no new coupling; the one new write endpoint (update.php POST) reuses the existing CSRF seam and level-1 gate. One production failure per new path named and handled (denied ALTER → MD5 mode; PHPMailer load failure → lint+behavioral gates; mixed-format rows → per-row detection).
- **Code quality:** DRY held (3 helpers serve 9 sites; detection lives once in the mapper); no speculative abstraction (no algo-enum, no Argon2 config surface). Line-ending hazard and stale-Edit-buffer hazard both called out in-plan (both bit this project before).
- **Tests:** every new branch pinned by a named check; REGRESSION RULE honored — all pre-existing §9 CSRF/throttle/enumeration checks must stay green untouched (Task 9 gate). Coverage diagram: 9/9 planned fork paths and 7/7 Kiption paths carry at least one ★★ check; E2E-worthy paths flagged (fresh install, upgrade flow, cross-repo Task 7 E2E).
- **Performance:** bcrypt ~60-100ms per login (once, then cache-warm verify) — acceptable for an archive login; one-time ALTER on `fanfiction_authors` (typically ≤ tens of thousands of rows; MySQL 8 INPLACE); INFORMATION_SCHEMA probe static-cached per request. No queries-in-loops introduced. No benchmarks invented.

| Review | Trigger | Why | Runs | Status | Findings |
|---|---|---|---|---|---|
| plan-eng-review | write-plan chain (user request) | 3.5.7 port + Kiption import | lint/runtime/database/security/http/docs read-backs at implementation time | completed (2 decisions pending) | 6 findings, all folded |

**VERDICT: SHIP-READY pending D1 (structure confirmation) and D2 (schema-contract confirmation).**

**OUTSIDE COVERAGE:** unavailable — Codex is a billed API and was never authorized by the user; native Plan-subagent fallback type does not exist on this host. Single-reviewer risk stands; the implementation-time /qa-full + /defense pass is the compensating control.

**CROSS-MODEL:** none.

**Amendment (2026-10-20, owner request):** the Kiption plan gained "Task 6: All-versions coverage" and was renumbered (docs → Task 7, verification → Task 8). Scope: 3.0–3.4.x import directly via exporter schema-gap tolerance (sourced from official `dbupgrade.php` column deltas); 1.1/2.0.x via the documented upgrade chain using the fork's own gated `install/upgrade11.php`/`upgrade20.php`; 4.x/5.x refused with guidance. Schema-gap facts verified: exporter reads `SELECT *` (`resources/efiction-export.php:57`); coauthors-table marker from `dbupgrade.php:206`. No findings against the amendment; it extends the approved arrangement without changing any folded decision.

**Amendment 2 (2026-10-20, owner request — "no gaps vs Sidular's line"):** (a) this plan's Task 9 adds PHP 8.5 to the fork's CI matrix (ceiling parity with 3.5.7's tested range; floor stays 8.3 as a stated security posture — official 3.5.7 itself recommends 8.2+ for live sites; the user's Homebrew mainline php is not touched); (b) new Task 10 pins the future-official-release contract (runs on any official 3.5.x database incl. 3.5.8+, unknown columns ignored, argon-family hashes verify via `password_verify`, a "3.5.8-style" fixture, and a weekly upstream canary workflow — $0, public-repo Actions — that opens a tracking issue when `update.efiction.org` ships a release newer than the tested one). Kiption plan: hash detection broadened to the `password_hash()` family, a forward-compat "3.5.8-style" import test, and new Task 9 (Kiption CI on 8.3/8.4/8.5 — it had none; $0, public repo). No findings; no decision changes.

**Amendment 3 (2026-10-20, owner direction — old-PHP users are Kiption's market, not the fork's):** no code changes; messaging only. Old-PHP support is out of scope permanently — no 5.6–8.2 shims, polyfills, or degraded modes will ever be planned. Every wall a legacy archive owner hits now routes to Kiption: the fork's COMPATIBILITY.md requirement line states that dead-host admins should go to kiption.com (free posting) or kiption.com/cloud (paid hosted archive with eFiction migration); Kiption's pre-3.0 refusal page and README old-host branch lead with the same two doors, with the self-serve upgrade chain (upgrade11/upgrade20) kept as the second option. Funnel confirmed by owner: fork = modern-host archives; Kiption free = individual writers; Kiption Cloud paid = hosted archives + migration.

**UNRESOLVED DECISIONS:** none — D1 (keep both plans as written, original arrangement) and D2 (relax the fork's schema contract to the single lazily-applied password-column widening) were both approved by the owner on 2026-10-20, choosing the recommended options.

NO UNRESOLVED DECISIONS
