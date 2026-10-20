<?php
/**
 * Security regression harness for the eFiction maintenance fork.
 *
 * Seeds the preserved 3.5.5 fixture (a known admin, an unvalidated story
 * owned by uid 10 with coauthor uid 11, and unrelated member uid 12) and
 * drives viewstory.php, viewpage.php, and the admin custom page and message
 * forms over session-backed HTTP requests:
 *
 *   - the unpublished story role matrix (guest, owner, coauthor, unrelated
 *     member, admin),
 *   - nonexistent story ids rendering without PHP warnings,
 *   - custom pages containing PHP never executing and never exposing their
 *     source,
 *   - forged admin writes (custom page submit/preview/delete and message
 *     edits) denied without changing rows.
 *
 * Required env: EF_DB_ADMIN_USER (plus optional EF_DB_ADMIN_PASSWORD,
 * EF_DB_HOST, EF_DB_PORT, EF_DB_NAME). Optional: PHP_BIN, MYSQL_BIN.
 * Run: php tests/compat/security.php
 */
$php = getenv('PHP_BIN') ?: PHP_BINARY;
$mysql = getenv('MYSQL_BIN') ?: 'mysql';
$repo = dirname(__DIR__, 2);
$dbHost = getenv('EF_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('EF_DB_PORT') ?: '';
$dbUser = getenv('EF_DB_ADMIN_USER') ?: 'root';
$dbPass = getenv('EF_DB_ADMIN_PASSWORD') ?: '';
$dbName = getenv('EF_DB_NAME') ?: ('efiction_test_sec_' . bin2hex(random_bytes(6)));
if (!preg_match('/^efiction_test_[a-z0-9_]+$/', $dbName)) {
	fwrite(STDERR, "refusing to run: EF_DB_NAME must match ^efiction_test_[a-z0-9_]+\$ (got: $dbName)\n");
	exit(2);
}

$fails = 0;
function check(string $label, bool $cond): void {
	global $fails;
	if ($cond) {
		echo "ok: $label\n";
	} else {
		$fails++;
		echo "FAIL: $label\n";
	}
	fflush(STDOUT);
}
function shell(string $cmd): void {
	fwrite(STDERR, "[" . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'] ?? 0, 1) . "] setup: " . strtok($cmd, " ") . "\n");
	exec($cmd . ' 2>&1', $out, $code);
	if ($code !== 0) {
		fwrite(STDERR, "setup command failed ($code): $cmd\n" . implode("\n", array_slice($out, -5)) . "\n");
		exit(1);
	}
}

$work = sys_get_temp_dir() . '/efiction-sec-' . bin2hex(random_bytes(4));
$web = "$work/web";
mkdir($web, 0777, true);
$marker = "$work/eval-marker";

function cleanup_work(string $work): void {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	@rmdir($work);
}

// ---- disposable webroot and database --------------------------------------
shell("cd " . escapeshellarg($repo) . " && tar --exclude='./.git' --exclude='./tests' --exclude='./dist' -cf - . | tar -xf - -C " . escapeshellarg($web));
$mysqlBase = escapeshellarg($mysql)
	. " -h " . escapeshellarg($dbHost)
	. ($dbPort ? " -P " . escapeshellarg($dbPort) : "")
	. " -u " . escapeshellarg($dbUser)
	. ($dbPass !== "" ? " -p" . escapeshellarg($dbPass) : "");
shell($mysqlBase . " -e " . escapeshellarg("DROP DATABASE IF EXISTS `$dbName`; CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"));
shell("gzip -dc " . escapeshellarg("$repo/tests/fixtures/efiction-3.5.5.sql.gz") . " | " . $mysqlBase . " " . escapeshellarg($dbName));
// The fixture's file backed chapters live beside the SQL dump.
shell("cp -R " . escapeshellarg("$repo/tests/fixtures/stories") . " " . escapeshellarg("$web/stories"));

// Fixture config; the web server reaches the database the same way the
// harness does.
$config = str_replace(
	array('%%DBHOST%%', '%%DBNAME%%', '%%DBUSER%%', '%%DBPASS%%'),
	array($dbPort ? "$dbHost:$dbPort" : $dbHost, $dbName, $dbUser, $dbPass),
	(string) file_get_contents("$repo/tests/fixtures/config.php.in")
);
file_put_contents("$web/config.php", $config);
chmod("$web/config.php", 0644);

// Direct database handle for row-state assertions (test database only).
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort !== "" ? (int) $dbPort : ini_get('mysqli.default_port'));
if ($db->connect_error) {
	fwrite(STDERR, "cannot connect to the test database: {$db->connect_error}\n");
	exit(1);
}
$db->set_charset('utf8mb4');
function row_scalar(mysqli $db, string $sql): ?string {
	$res = $db->query($sql);
	$row = $res ? $res->fetch_row() : null;
	return $row === null ? null : (string) $row[0];
}

// ---- local HTTP server ------------------------------------------------------
$socket = false;
$port = 0;
for ($i = 0; $i < 50 && $socket === false; $i++) {
	$port = random_int(21000, 44000);
	$socket = @socket_create_listen($port);
}
if ($socket === false) { fwrite(STDERR, "could not allocate a port\n"); exit(1); }
socket_close($socket);
$server = proc_open(escapeshellarg($php) . " -S 127.0.0.1:" . $port . " -t " . escapeshellarg($web)
	. " -d display_errors=1 -d error_reporting=E_ALL -d opcache.enable=0 -d opcache.enable_cli=0"
	. " > " . escapeshellarg("$work/server.log") . " 2>&1",
	array(), $serverPipes);
if (!is_resource($server)) { fwrite(STDERR, "could not start the test server\n"); exit(1); }
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 100; $i++) {
	$ch = curl_init("$base/install/install.php");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 2);
	$probe = curl_exec($ch);
	curl_close($ch);
	if ($probe !== false && $probe !== '') break;
	usleep(100000);
}

function http(string $method, string $url, ?string &$body, ?string &$status, string $jar = '', array $post = array()): void {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	if ($jar !== '') {
		curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
	}
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
	}
	$raw = (string) curl_exec($ch);
	$status = (string) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	$split = strpos($raw, "\r\n\r\n");
	$body = $split === false ? $raw : substr($raw, $split + 4);
}
function get(string $url, ?string &$body, ?string &$status, string $jar = ''): void {
	http('GET', $url, $body, $status, $jar);
}
function post(string $url, array $fields, ?string &$body, ?string &$status, string $jar = ''): void {
	http('POST', $url, $body, $status, $jar, $fields);
}

// ---- sessions ---------------------------------------------------------------
$guestJar = "$work/jar-guest";
$adminJar = "$work/jar-admin";
$ownerJar = "$work/jar-owner";
$coauthorJar = "$work/jar-coauthor";
$memberJar = "$work/jar-member";
function login(string $jar, string $base, string $penname, string $password): void {
	$body = ''; $status = '';
	post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => $penname, 'password' => $password), $body, $status, $jar);
	if ($status !== '200') { fwrite(STDERR, "login for $penname failed: $status\n"); exit(1); }
}
login($adminJar, $base, 'FixtureAdmin', 'fixturepass123');
login($ownerJar, $base, 'AuthorOwner', 'authorpass123');
login($coauthorJar, $base, 'CoAuthor', 'coauthorpass123');
login($memberJar, $base, 'OtherMember', 'memberpass123');

// ---- 1. unpublished story role matrix ---------------------------------------
// Fixture: story 2 is unvalidated, owned by uid 10 (AuthorOwner), with
// coauthor uid 11 (CoAuthor); uid 12 (OtherMember) is unrelated. Its file
// backed chapter contains "Hidden chapter text preserved fixture."
$hidden = 'Hidden chapter text preserved fixture.';
$denied = 'Access denied. This story has not been validated';

get("$base/viewstory.php?sid=2", $body, $status, $guestJar);
check("guest is denied the unvalidated story", $status === '200' && strpos($body, $denied) !== false && strpos($body, $hidden) === false);

get("$base/viewstory.php?sid=2", $body, $status, $memberJar);
check("unrelated member is denied the unvalidated story", strpos($body, $denied) !== false && strpos($body, $hidden) === false);

get("$base/viewstory.php?sid=2", $body, $status, $ownerJar);
check("owner reads the unvalidated story", strpos($body, 'Unvalidated Fixture Story') !== false && strpos($body, $hidden) !== false);

get("$base/viewstory.php?sid=2", $body, $status, $coauthorJar);
check("coauthor reads the unvalidated story", strpos($body, 'Unvalidated Fixture Story') !== false && strpos($body, $hidden) !== false);

get("$base/viewstory.php?sid=2", $body, $status, $adminJar);
check("admin reads the unvalidated story", strpos($body, 'Unvalidated Fixture Story') !== false && strpos($body, $hidden) !== false);

get("$base/viewstory.php?sid=1", $body, $status, $guestJar);
check("guest reads the validated story", strpos($body, 'Fixture Story') !== false && strpos($body, 'Chapter One text preserved fixture.') !== false);

// Nonexistent story id: rendered safely, no PHP errors, no dereference of a
// missing row.
get("$base/viewstory.php?sid=99999", $body, $status, $guestJar);
check("nonexistent story renders a safe message", strpos($body, 'That story does not exist') !== false);
check("nonexistent story has no PHP errors", !preg_match('/\b(Warning|Fatal error|Parse error|Deprecated|Notice)\b/', (string) $body));

// ---- 2. custom pages must not execute stored PHP ----------------------------
// A validated plain page must render exactly as stored.
$db->query("INSERT INTO fanfiction_messages (message_name, message_title, message_text) VALUES ('plainpage', 'Plain Page', '<p>A plain paragraph.</p>')");

// A PHP-bearing page with a marker payload inside the harness work dir.
$payload = '<?php file_put_contents(' . var_export($marker, true) . ', "executed"); ?>ZVISIBLE';
$db->query("INSERT INTO fanfiction_messages (message_name, message_title, message_text) VALUES ('secpage', 'Security Page', '" . $db->real_escape_string($payload) . "')");

@unlink($marker);
get("$base/viewpage.php?page=plainpage", $body, $status, $guestJar);
check("plain custom page renders as stored", strpos($body, 'A plain paragraph.') !== false);

@unlink($marker);
get("$base/viewpage.php?page=secpage", $body, $status, $guestJar);
check("php-bearing page does not execute", !is_file($marker));
check("php-bearing page does not expose its source", strpos((string) $body, 'file_put_contents') === false);
check("php-bearing page does not output the payload text", strpos((string) $body, 'ZVISIBLE') === false);

// ---- 3. admin custom page form: preview, write, delete ----------------------
// Preview a PHP-bearing draft: the eval path in admin/custpages.php must not
// run it either.
@unlink($marker);
post("$base/admin.php?action=custpages&new=1", array(
	'preview' => 'Preview', 'name' => 'secpage2', 'title' => 'Security Preview',
	'text' => $payload,
), $body, $status, $adminJar);
check("admin preview does not execute the draft", !is_file($marker));
check("admin preview does not expose the draft source", strpos((string) $body, 'file_put_contents') === false);

// A forged write without the session token must be denied and change
// nothing. The fixture admin session has no token until the form is fetched.
$rows_before = row_scalar($db, "SELECT COUNT(*) FROM fanfiction_messages");
post("$base/admin.php?action=custpages", array(
	'submit' => 'Submit', 'name' => 'forged', 'title' => 'Forged', 'text' => 'forged text',
), $body, $status, $adminJar);
check("forged custom page POST is denied (403)", $status === '403');
check("forged custom page POST changed no rows", row_scalar($db, "SELECT COUNT(*) FROM fanfiction_messages") === $rows_before);

// The legitimate form flow still works: fetch the form (token), then submit.
get("$base/admin.php?action=custpages&new=1", $body, $status, $adminJar);
if (!preg_match("/name='csrf' value='([0-9a-f]+)'/", (string) $body, $m)) {
	// Token field may be double-quoted; accept either.
	preg_match('/name="csrf" value="([0-9a-f]+)"/', (string) $body, $m);
}
check("custom page form carries a session token", isset($m[1]));
if (isset($m[1])) {
	post("$base/admin.php?action=custpages", array(
		'submit' => 'Submit', 'csrf' => $m[1], 'name' => 'legitpage', 'title' => 'Legit Page', 'text' => '<p>Legit content.</p>',
	), $body, $status, $adminJar);
	check("tokened custom page POST succeeds (200)", $status === '200');
	check("tokened custom page POST inserts the row", row_scalar($db, "SELECT COUNT(*) FROM fanfiction_messages WHERE message_name = 'legitpage'") === '1');
}

// GET delete must be denied; the row survives.
$secpage_id = row_scalar($db, "SELECT message_id FROM fanfiction_messages WHERE message_name = 'secpage'");
get("$base/admin.php?action=custpages&delete=$secpage_id", $body, $status, $adminJar);
check("GET delete of a custom page is denied", strpos((string) $body, 'was deleted') === false);
check("GET delete changed no rows", row_scalar($db, "SELECT COUNT(*) FROM fanfiction_messages WHERE message_id = '$secpage_id'") === '1');

// ---- 4. admin message settings: forged writes and SQL quoting ---------------
// The message editor interpolates $_GET['message'] into its queries and had
// no write token. A forged POST with a quoted message name must not alter
// the row.
$welcome_before = row_scalar($db, "SELECT message_text FROM fanfiction_messages WHERE message_name = 'welcome'");
post("$base/admin.php?action=messages&message=" . rawurlencode("welcome' OR message_name='welcome"), array(
	'submit' => 'Submit', 'text' => 'forged welcome text',
), $body, $status, $adminJar);
check("forged message edit with a quoted name is denied", $status === '403' || strpos((string) $body, 'Forbidden') !== false);
check("forged message edit changed no rows", row_scalar($db, "SELECT message_text FROM fanfiction_messages WHERE message_name = 'welcome'") === $welcome_before);

// The legitimate tokened flow still edits the message.
get("$base/admin.php?action=messages&message=welcome", $body, $status, $adminJar);
if (preg_match("/name='csrf' value='([0-9a-f]+)'/", (string) $body, $m) || preg_match('/name="csrf" value="([0-9a-f]+)"/', (string) $body, $m)) {
	post("$base/admin.php?action=messages&message=welcome", array(
		'submit' => 'Submit', 'csrf' => $m[1], 'text' => 'A new welcome message.',
	), $body, $status, $adminJar);
	check("tokened message edit succeeds", row_scalar($db, "SELECT message_text FROM fanfiction_messages WHERE message_name = 'welcome'") === 'A new welcome message.');
} else {
	check("message edit form carries a session token", false);
}

// ---- 8. full-codebase QA hardening (2026-10-15) ------------------------------
// Regression checks for the second hardening pass: cookie SQL injection in
// the session bootstrap, the editbio authorization hole, browse.php input
// validation, skin path traversal, the review edit/update IDOR, stored
// script in author responses, and the printable chapter fatal.

// Crafted remember-me cookie: a quote in the uid cookie reached the session
// bootstrap query before the salt comparison, so upstream every page load
// ran attacker SQL. The jar carries the urlencoded quote.
$badCookieJar = "$work/jar-badcookie";
@unlink($badCookieJar);
get("$base/index.php", $body, $status);
file_put_contents($badCookieJar, "127.0.0.1\tFALSE\t/\tFALSE\t0\tfixture355key_useruid\t%27\n");
get("$base/index.php", $body, $status, $badCookieJar);
check("quote-bearing remember-me cookie renders the guest page", $status === '200' && strpos((string) $body, 'A fatal MySQL error') === false);
check("quote-bearing remember-me cookie prints no PHP errors", preg_match('/<(Warning|Fatal error|Parse error|Deprecated|Notice)\b/', (string) $body) !== 1);

// editbio authorization: upstream appended a not-authorized message but kept
// processing, so any member could reset another account's password.
$ownerHashBefore = row_scalar($db, "SELECT password FROM fanfiction_authors WHERE uid = 10");
post("$base/user.php?action=editbio&uid=10", array(
	'submit' => 'Submit', 'password' => 'takenover 789', 'password2' => 'takenover 789',
	'email' => 'owner@example.com', 'realname' => 'Owner',
), $body, $status, $memberJar);
check("member cannot reset another account's password", row_scalar($db, "SELECT password FROM fanfiction_authors WHERE uid = 10") === $ownerHashBefore);
post("$base/user.php?action=editbio&uid=10%27%20OR%20%271%27%3D%271", array(
	'submit' => 'Submit', 'password' => 'takenover 789', 'password2' => 'takenover 789',
	'email' => 'owner@example.com', 'realname' => 'Owner',
), $body, $status, $memberJar);
check("injected editbio uid changes no password", row_scalar($db, "SELECT password FROM fanfiction_authors WHERE uid = 10") === $ownerHashBefore);

// browse.php: search.php's escapestring/isNumber treatment was missing here.
// A lone trailing quote breaks the LIKE quoting when unescaped (upstream:
// fatal error page); escaped it is a harmless literal. Sidebar blocks list
// story titles on every page, so title-absence is not a usable signal.
get("$base/browse.php?type=titles&searchterm=x%27", $body, $status);
check("browse searchterm quote neither errors nor matches everything", $status === '200' && strpos((string) $body, 'A fatal MySQL error') === false && preg_match('/<(Warning|Fatal error|Deprecated)\b/', (string) $body) !== 1);
post("$base/browse.php?type=titles", array(
	'submit' => 'Go', 'wordlow' => "1' OR '1'='1", 'wordhigh' => '999999',
), $body, $status);
check("browse wordcount bounds are numeric only", $status === '200' && strpos((string) $body, 'A fatal MySQL error') === false);

// Skin path traversal: a skin name containing ../ redirected the skin
// includes out of skins/. A planted directory proves inclusion.
@mkdir("$web/evil", 0777);
file_put_contents("$web/evil/variables.php", "<?php file_put_contents(__DIR__ . '/pwned-marker.txt', 'x'); ");
get("$base/index.php?skin=..%2Fevil", $body, $status);
check("skin traversal does not include outside directories", !file_exists("$web/evil/pwned-marker.txt"));

// Review edit IDOR: upstream updated any review by id with no ownership
// check. Seed one review owned by uid 10 and try to rewrite it as uid 12.
$db->query("INSERT INTO fanfiction_reviews (item, chapid, reviewer, review, rating, date, uid, respond, type) VALUES (1, 0, 'AuthorOwner', 'Original review text.', 0, NOW(), 10, '0', 'ST')");
$reviewid = (string) $db->insert_id;
post("$base/reviews.php?action=edit&reviewid=$reviewid", array(
	'submit' => 'Submit', 'review' => 'Rewritten by intruder.', 'rating' => '5',
), $body, $status, $memberJar);
check("member cannot rewrite another member's review", row_scalar($db, "SELECT review FROM fanfiction_reviews WHERE reviewid = $reviewid") === 'Original review text.');

// Author response storage: upstream appended the response without
// strip_tags, so a script tag reached every visitor of the review page.
// The response form is rendered to the review owner on the revres panel.
get("$base/user.php?action=revres&reviewid=$reviewid", $body, $status, $ownerJar);
if (preg_match("/name=[\"']rid[\"'] value=[\"']([0-9]+)[\"']/", (string) $body, $ridMatch)) {
	post("$base/user.php?action=revres", array(
		'submit' => 'Submit', 'rid' => $ridMatch[1], 'response' => '<script>alert(1)</script>harmless words',
	), $body, $status, $ownerJar);
	check("author response stores no script tag", strpos((string) row_scalar($db, "SELECT review FROM fanfiction_reviews WHERE reviewid = " . (int) $ridMatch[1]), '<script>') === false);
} else {
	check("review response form is reachable for the story owner", false);
}

// Printable unvalidated chapter: the fork's chapter access check read a
// variable that had been unset, fatalling on PHP 8. Insert one unvalidated
// chapter into validated story 1 and read it as a guest.
$db->query("INSERT INTO fanfiction_chapters (title, inorder, storytext, validated, sid, uid) VALUES ('QA unvalidated chapter', 99, 'Hidden QA chapter text.', 0, 1, 10)");
get("$base/viewstory.php?action=printable&sid=1&chapter=99", $body, $status, $guestJar);
check("printable unvalidated chapter renders without a PHP fatal", $status === '200' && preg_match('/<(Fatal error|Warning|Deprecated)\b/', (string) $body) !== 1);

// ---- 9. admin-area CSRF gate (2026-10-15, second pass) ------------------------
// admin.php verifies the session token on every POST and its output buffer
// injects the token field into every rendered form; the GET mutations that
// upstream shipped as links are POST forms now. A sacrificial member row
// proves each side.

$db->query("INSERT INTO fanfiction_authors (uid, penname, realname, bio, email, date, password) VALUES (99, 'CsrfTarget', '', '', 'csrftarget@example.com', NOW(), 'x')");
check("csrf sacrificial member exists", row_scalar($db, "SELECT penname FROM fanfiction_authors WHERE uid = 99") === 'CsrfTarget');

// A forged POST (no token) to a destructive panel action is rejected with
// 403 and changes nothing.
post("$base/admin.php?action=members", array('confirm' => 'yes', 'delete' => '99'), $body, $status, $adminJar);
check("forged admin POST is denied (403)", $status === '403');
check("forged admin POST deleted no member", row_scalar($db, "SELECT penname FROM fanfiction_authors WHERE uid = 99") === 'CsrfTarget');

// The upstream GET link no longer executes anything.
get("$base/admin.php?action=members&confirm=yes&delete=99", $body, $status, $adminJar);
check("legacy GET delete link executes nothing", $status === '200' && row_scalar($db, "SELECT penname FROM fanfiction_authors WHERE uid = 99") === 'CsrfTarget');

// Every admin page carries the injected token in its forms (the settings
// panel always renders a large POST form).
get("$base/admin.php?action=settings", $body, $status, $adminJar);
check("admin pages inject the token into forms", preg_match("/name=['\"]csrf['\"] value=['\"][0-9a-f]{64}['\"]/", (string) $body) === 1);
preg_match("/name=['\"]csrf['\"] value=['\"]([0-9a-f]{64})['\"]/", (string) $body, $adminCsrf);

// A tokened POST executes: the sacrificial member is deleted.
if ($adminCsrf) {
	post("$base/admin.php?action=members", array('confirm' => 'yes', 'delete' => '99', 'csrf' => $adminCsrf[1]), $body, $status, $adminJar);
	check("tokened admin POST executes", $status === '200' && row_scalar($db, "SELECT penname FROM fanfiction_authors WHERE uid = 99") === null);
} else {
	check("tokened admin POST executes", false);
}

// The admin-flagged story delete requires the token too.
post("$base/stories.php?action=delete", array('confirmed' => 'yes', 'sid' => '1', 'admin' => '1', 'uid' => '10'), $body, $status, $adminJar);
check("forged admin story delete is denied", row_scalar($db, "SELECT title FROM fanfiction_stories WHERE sid = 1") === 'Fixture Story');

// The validation panel's mutation ignores GET and a forged POST alike
// (adversarial pass: this panel was missed in the first conversion round).
$chapterValidBefore = row_scalar($db, "SELECT validated FROM fanfiction_chapters WHERE chapid = 2");
get("$base/admin.php?action=validate&sid=2&chapid=2&validate=yes", $body, $status, $adminJar);
check("validation GET link publishes nothing", row_scalar($db, "SELECT validated FROM fanfiction_chapters WHERE chapid = 2") === $chapterValidBefore);
post("$base/admin.php?action=validate&sid=2&chapid=2", array('validate' => 'yes'), $body, $status, $adminJar);
check("forged validation POST publishes nothing", $status === '403' && row_scalar($db, "SELECT validated FROM fanfiction_chapters WHERE chapid = 2") === $chapterValidBefore);

// The classifications type delete works through the tokened POST (its
// handler read type_id from GET only after conversion and was a no-op).
$db->query("INSERT INTO fanfiction_classtypes (classtype_id, classtype_title, classtype_name) VALUES (99, 'QA Type', 'qatype')");
if (!empty($adminCsrf)) {
	post("$base/admin.php?action=classifications", array('delete' => 'type', 'type_id' => '99', 'csrf' => $adminCsrf[1]), $body, $status, $adminJar);
	check("tokened classifications type delete executes", $status === '200' && row_scalar($db, "SELECT classtype_name FROM fanfiction_classtypes WHERE classtype_id = 99") === null);
} else {
	check("tokened classifications type delete executes", false);
}

// Lost-password responses no longer distinguish known from unknown mail.
// (Both addresses must pass validEmail, or the panel rejects the input
// before the uniform branch.)
post("$base/user.php?action=lostpassword", array('submit' => 'Submit', 'email' => 'nobody@example.com'), $body, $status);
$unknownMail = strpos((string) $body, 'A new password has been sent to your e-mail address.') !== false;
post("$base/user.php?action=lostpassword", array('submit' => 'Submit', 'email' => 'somebody-else@example.com'), $body, $status);
check("lost-password response is uniform", $unknownMail && strpos((string) $body, 'A new password has been sent to your e-mail address.') !== false);
// Both probe posts counted toward the rate limit; clear them so later runs
// and the harness's own flows are unaffected.
$db->query("DELETE FROM fanfiction_log WHERE log_type = 'LP' AND log_ip = INET_ATON('127.0.0.1')");

// Login brute-force throttle: ten failures lock the form for this address;
// the lock is then cleared so nothing downstream inherits it.
for ($attempt = 0; $attempt < 10; $attempt++) {
	post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'FixtureAdmin', 'password' => 'wrong password'), $body, $status, $guestJar);
}
post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'FixtureAdmin', 'password' => 'fixturepass123'), $body, $status, $guestJar);
check("eleventh attempt is throttled even with the right password", $status === '200' && strpos((string) $body, 'Too many failed sign-in attempts') !== false);
$db->query("DELETE FROM fanfiction_log WHERE log_type = 'LF' AND log_ip = INET_ATON('127.0.0.1')");
post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'FixtureAdmin', 'password' => 'fixturepass123'), $body, $status, $guestJar);
check("login works again once the throttle window is cleared", $status === '200' && strpos((string) $body, 'name="pswd"') === false);

// ---- 10. bcrypt upgrade-on-login (3.5.7 port) ---------------------------------
// A legacy md5 row upgrades transparently at login, the same password keeps
// working, and a wrong password is still rejected. The salt assertion is the
// re-login itself: get_session_vars compares md5(email . stored_password)
// unchanged, so a fresh login after the row turned bcrypt proves the salt is
// derived from the stored hash. The remember-me variant proves the cookie
// path on a session-less jar (only the uid/salt pair authenticates).
$upgradeJar = "$work/jar-upgrade";
post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'OtherMember', 'password' => 'memberpass123'), $body, $status, $upgradeJar);
check("legacy md5 login upgrades row to bcrypt",
    strncmp((string) row_scalar($db, "SELECT password FROM fanfiction_authors WHERE penname = 'OtherMember'"), '$2y$', 4) === 0);

post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'OtherMember', 'password' => 'memberpass123'), $body, $status, $upgradeJar);
check("bcrypt user re-logins with same password (salt from stored hash)",
    $status === '200' && strpos((string) $body, 'name="pswd"') === false);

post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'OtherMember', 'password' => 'not-the-password'), $body, $status, $upgradeJar);
check("wrong password rejected after upgrade",
    strpos((string) $body, "doesn't match the one in our database") !== false);
// Clear the failed attempt so later runs inherit no throttle rows.
$db->query("DELETE FROM fanfiction_log WHERE log_type = 'LF' AND log_ip = INET_ATON('127.0.0.1')");

$cookieJar = "$work/jar-upgrade-cookie";
post("$base/user.php?action=login", array('submit' => 'submit', 'penname' => 'OtherMember', 'password' => 'memberpass123', 'cookiecheck' => '1'), $body, $status, $cookieJar);
// Strip the PHP session cookie so ONLY the remember-me pair can authenticate;
// validating then proves the cookie salt was derived from the stored bcrypt
// hash (md5(email . stored_hash), get_session_vars.php unchanged).
$jarLines = file($cookieJar, FILE_IGNORE_NEW_LINES);
$strippedJar = '';
foreach ($jarLines as $jarLine) {
    if ($jarLine !== '' && strpos($jarLine, '#') !== 0 && preg_match("/\tPHPSESSID\t/", $jarLine) !== 1) $strippedJar .= "$jarLine\n";
}
file_put_contents($cookieJar, $strippedJar);
get("$base/user.php", $body, $status, $cookieJar);
check("remember-me cookie authenticates against the bcrypt row",
    $status === '200' && strpos((string) $body, 'name="pswd"') === false);

// Reset for later sections so md5-era assumptions elsewhere still hold:
$db->query("UPDATE fanfiction_authors SET password = '" . md5('memberpass123') . "' WHERE penname = 'OtherMember'");

// ---- teardown ---------------------------------------------------------------
if ($fails) {
	// Keep the disposable environment for inspection unless KEEP_WORK=0.
	if (getenv('KEEP_WORK') !== '0') {
		fwrite(STDERR, "work dir kept for inspection: $work\n");
	} else {
		proc_terminate($server);
		proc_close($server);
		cleanup_work($work);
	}
	fwrite(STDERR, "$fails failure(s)\n");
	exit(1);
}
proc_terminate($server);
proc_close($server);
$db->close();
cleanup_work($work);
echo "security ok\n";
