<?php
/**
 * Runtime compatibility probes for the eFiction maintenance fork.
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/compat/runtime.php
 * Exits non-zero on the first failure list it prints.
 */
define('_CHARSET', 'UTF-8');
$root = dirname(__DIR__, 2);
require $root . '/includes/corefunctions.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    if ($cond) {
        echo "ok: $label\n";
    } else {
        $fails++;
        echo "FAIL: $label\n";
    }
}

check('php 8.3 floor', PHP_VERSION_ID >= 80300);
check('corefunctions api present', function_exists('search') && function_exists('replace_naughty') && function_exists('find_naughty') && function_exists('validEmail'));

// Page bootstrap: the word-masking path must run and keep its exact legacy output.
global $words;
$words = array('bad');
check('word masking', replace_naughty('bad') === 'b**');

// Multibyte record: replace_naughty is byte oriented. The kept prefix is the
// first BYTE (not the first character) and the pad length is the BYTE length
// (not the character count): baüd (4 chars, 5 bytes) becomes b**** (5 bytes).
// Words whose first byte is multibyte never match at all in the byte-mode
// regexes. This records the legacy byte output so the PHP 8 offset fix
// cannot silently change it.
$words = array("ba\xC3\xBCd"); // baüd in UTF-8
$masked = replace_naughty("ba\xC3\xBCd");
$expected = "b****"; // first byte plus stars up to the byte length (5)
check('multibyte legacy byte behavior unchanged', $masked === $expected);

// descript(): the on*-attribute strip must remove every event handler
// (including onerror/onfocus/onclick in any case) while PRESERVING tags
// whose attribute values merely start with an on-word. The unanchored
// pattern collapsed <a title="only once"> entirely (review finding).
// 3.5.78 port: the vectors their attribute rebuilder closes that plain
// scheme filters miss - control characters inside the scheme, data: URLs
// carrying markup - plus the safe cases that must keep working.
check('descript blocks control-character-obfuscated script urls',
    strpos(descript('<a href="jav&#9;ascript:alert(1)">x</a>'), 'ascript:') === false
        && strpos(descript("<a href=\"jav\tascript:alert(1)\">x</a>"), 'ascript:') === false);
check('descript blocks data: urls carrying markup, keeps safe image data',
    strpos(descript('<a href="data:text/html,<script>alert(1)</script>">x</a>'), 'data:text/html') === false
        && strpos(descript('<img src="data:image/png;base64,iVBOR">'), 'data:image/png') !== false);
check('descript blocks entity-encoded schemes and srcdoc',
    strpos(descript('<a href="&#106;avascript:alert(1)">x</a>'), 'avascript:') === false
        && strpos(descript('<iframe srcdoc="<script>alert(1)</script>">'), 'srcdoc') === false);
check('descript keeps ordinary links and text untouched',
    strpos(descript('<a href="https://example.com/page">ok</a> and plain words'), 'https://example.com/page') !== false);

check('descript strips onerror handler', strpos(descript('<img src=x onerror=alert(1)>'), 'onerror') === false);
check('descript strips spaced onclick', strpos(descript('<a ONCLICK ="x()">y</a>'), 'ONCLICK') === false);
check('descript strips xmlns', strpos(descript('<span xmlns="http://x">z</span>'), 'xmlns') === false);
check('descript preserves on-word attribute values', descript('<a title="only once">text</a>') === '<a title="only once">text</a>');
check('descript preserves on-word hrefs', strpos(descript('<a href="once.php">link</a>'), 'once.php') !== false);
check('descript leaves prose untouched', descript('Consider the onclick handler') === 'Consider the onclick handler');

// ef_password_* helpers: hash format, verify, upgrade detection, session salt.
$pw_hash = ef_hash_password('swordfish');
check('ef_hash_password produces bcrypt', is_string($pw_hash) && strncmp($pw_hash, '$2y$', 4) === 0 && strlen($pw_hash) === 60);
check('ef_verify_password accepts md5 legacy and bcrypt',
    ef_verify_password('swordfish', md5('swordfish'))
    && ef_verify_password('swordfish', ef_hash_password('swordfish'))
    && !ef_verify_password('wrong', md5('swordfish'))
    && !ef_verify_password('wrong', ef_hash_password('swordfish')));
check('ef_password_needs_upgrade detects only md5',
    ef_password_needs_upgrade(md5('x')) === true
        && ef_password_needs_upgrade(ef_hash_password('x')) === false
        && ef_password_needs_upgrade('0') === false);
// Adversarial-review finding: crafted password[] POST fields must never
// reach md5()/password_hash()/password_verify(), which TypeError on arrays
// (a remotely triggerable HTTP 500 on the login form).
check('password helpers tolerate array-typed input without fatals',
    ef_verify_password(array('x'), md5('x')) === false
        && is_string(ef_hash_password(array('x')))
        && ef_verify_password(array('x'), ef_hash_password('swordfish')) === false);
$pw_stored = ef_hash_password('swordfish');
check('ef_session_salt derives from stored hash',
    ef_session_salt('a@b.c', $pw_stored) === md5('a@b.c' . $pw_stored)
    && ef_session_salt('a@b.c', md5('swordfish')) === md5('a@b.c' . md5('swordfish')));

// Forward compatibility: if a future official release (or a future PHP
// PASSWORD_DEFAULT) stores argon2id hashes, ef_verify_password already
// adjudicates them via password_verify and never flags them for md5 upgrade.
if (defined('PASSWORD_ARGON2ID')) {
    $argon = password_hash('swordfish', PASSWORD_ARGON2ID);
    check('ef_verify_password accepts argon2id without upgrade flag',
        ef_verify_password('swordfish', $argon) && !ef_verify_password('wrong', $argon)
        && ef_password_needs_upgrade($argon) === false);
} else {
    check('argon2id unavailable on this runtime (skipped, pinned by CI)', true);
}

// Login salt: the four salt sites must use string concatenation. Arithmetic
// addition on strings is a TypeError on PHP 8, which broke login and the
// remember-me cookie.
$email = 'user@example.com';
$hash = str_repeat('a', 32);
$typeError = false;
try {
    md5($email + $hash);
} catch (TypeError $e) {
    $typeError = true;
}
check('arithmetic salt is a TypeError on php 8 (documents the old bug)', $typeError);
check('concatenated salt is the md5 of the joined strings', md5($email . $hash) === md5('user@example.com' . str_repeat('a', 32)));
foreach (array('user/login.php', 'includes/get_session_vars.php') as $f) {
    $src = file_get_contents($root . '/' . $f);
    check("$f uses no arithmetic md5 salt", preg_match('/md5\([^)]*\+[^)]*\)/', $src) !== 1);
}

// Adversarial-review findings, pinned at source level (the harness drives
// the main copies end to end; the default-bridge copies must carry the same
// gates): the bridge's uid cookie passes isNumber before SQL, and the
// bridge's lost-password escapes the email and never seeds mt_srand.
$bridgeSession = file_get_contents($root . '/bridges/default/get_session_vars.php');
check('bridge get_session_vars gates the uid cookie with isNumber',
    strpos($bridgeSession, 'isNumber($_COOKIE[$sitekey."_useruid"])') !== false);
$bridgeLost = file_get_contents($root . '/bridges/default/lostpassword.php');
check('bridge lost-password escapes the email before SQL',
    strpos($bridgeLost, "escapestring(descript(\$_POST['email']))") !== false);
// Item 64 (official 3.5.82/3.5.83/3.5.84 ports): search wildcards are
// literal (explicit ESCAPE '!' clauses), full-text search reads only
// validated chapter text through EXISTS, unknown modes are refused, and
// the category dropdown endpoint escapes the reflected element name.
$searchSrc = file_get_contents($root . '/search.php');
check('search escapes LIKE wildcards with an explicit escape char',
    substr_count($searchSrc, "ESCAPE '!'") >= 6
        && strpos($searchSrc, 'array("!" => "!!", "%" => "!%", "_" => "!_")') !== false);
check('fulltext search is EXISTS over validated chapter text',
    strpos($searchSrc, 'chapter.validated > 0 AND chapter.storytext LIKE') !== false
        && strpos($searchSrc, 'in_array($searchtype, array("title", "summary", "penname", "advanced", "fulltext"), true)') !== false);
$catSrc = file_get_contents($root . '/includes/categorylist.php');
$charSrc = file_get_contents($root . '/includes/characterlist.php');
check('characterlist mirrors the endpoint treatment and escapes the element parameter',
    strpos($charSrc, "preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*\$/D', \$_GET['element'])") !== false
        && strpos($charSrc, 'addslashes($_GET') !== false
        && strpos($charSrc, "escapestring(\$sitekey)") !== false
        && strpos($charSrc, "preg_match('/^[A-Za-z0-9_-]+\$/D', \$settings['language'])") !== false
        && strpos($charSrc, "\$action = '';") !== false
        && strpos($charSrc, "header('X-Content-Type-Options: nosniff')") !== false
        && strpos($charSrc, 'Content-Type: text/javascript') !== false
        && strpos($charSrc, 'urlencode(str_replace($find') !== false);

check('categorylist validates the element parameter and JSON-encodes it (3.5.88 port)',
    strpos($catSrc, "preg_match('/^(?:[A-Za-z_][A-Za-z0-9_.-]*|[0-9]+)\$/D', \$_GET['element'])") !== false
        && strpos($catSrc, 'ef_category_json($element)') !== false);
check('categorylist sends nosniff, escapes the sitekey, and tolerates old schemas',
    strpos($catSrc, "header('X-Content-Type-Options: nosniff')") !== false
        && strpos($catSrc, "escapestring(\$sitekey)") !== false
        && strpos($catSrc, "SHOW COLUMNS FROM ") !== false
        && strpos($catSrc, "in_array('leveldown', \$ef_has)") !== false
        && strpos($catSrc, "\$action = '';") !== false
        && strpos($catSrc, 'urlencode(ef_category_name(stripslashes(') !== false
        && strpos($catSrc, 'urlencode(ef_category_utf8(') === false);

// Item 66 (official 3.5.85-3.5.88 port): category fields follow a
// storage/rendering policy of their own. The engine intersects allowed_tags
// with a static safe-element list, so a permissive site setting must not
// resurrect executable markup.
require_once $root . '/includes/category_helpers.php';
global $allowed_tags;
$allowed_tags = '<b><i><img><script><a>';
check('category name is plain text: tags stripped after entity-decode',
    ef_category_name('&lt;script&gt;alpha &lt;b&gt;beta') === 'alpha beta');
check('category label renders a stored dirty name escaped and tag-free',
    ef_category_label('<img src=x onerror=alert(1)> A &amp; B \\\\') === 'A &amp; B \\');
check('category image accepts a plain filename',
    ef_category_image('cat-icon.png') === 'cat-icon.png');
check('category image rejects traversal, absolute paths, control chars and quotes',
    ef_category_image('../../config.php') === ''
        && ef_category_image('/etc/passwd') === ''
        && ef_category_image("a\tx.png") === ''
        && ef_category_image('a"b.png') === ''
        && ef_category_image('a?b.png') === '');
check('category JSON embedding emits no raw angle brackets',
    strpos(ef_category_json('</script><script>'), '</') === false
        && strpos(ef_category_json('</script><script>'), '<script') === false);
check('category description drops script tags even when allowed_tags permits them, content renders inert',
    strpos(ef_category_description('<script>alert(1)</script>text'), '<script') === false
        && strpos(ef_category_description('<script>alert(1)</script>text'), 'alert(1)') !== false);
check('category description drops event handlers and javascript: links',
    strpos(ef_category_description('<img src="https://x/i.png" onerror="alert(1)">'), 'onerror') === false
        && strpos(ef_category_description('<a href="javascript:alert(1)">c</a>'), 'javascript:') === false);
check('category description keeps mailto and safe style, drops expression style',
    ($GLOBALS['allowed_tags'] = '<b><i><img><a><span>') !== false
        && strpos(ef_category_description('<a href="mailto:x@y.z">m</a>'), 'mailto:x@y.z') !== false
        && strpos(ef_category_description('<span style="color:red">s</span>'), 'color:red') !== false
        && strpos(ef_category_description('<span style="width:expression(alert(1))">s</span>'), 'expression') === false
        && ($GLOBALS['allowed_tags'] = '<b><i><img><script><a>') !== false);
check('category description re-escapes entity-decoded text',
    ef_category_description('&lt;b&gt;') === '&lt;b&gt;');
check('category description keeps safe configured markup and balances tags',
    strpos(ef_category_description('<b>bold</b><i>italic'), '<b>bold</b><i>italic</i>') !== false
        && strpos(ef_category_description('<img src="https://x/i.png" alt="p">'), '<img src="https://x/i.png" alt="p">') !== false);
check('category description balances deep nesting exactly (stack counter, not the quadratic scan)',
    ef_category_description(str_repeat('<b>', 50) . 'deep' . str_repeat('</b>', 50))
        === str_repeat('<b>', 50) . 'deep' . str_repeat('</b>', 50)
        && ef_category_description('<b><i>x</b>y</i>') === '<b><i>x</i></b>y');
$admCatSrc = file_get_contents($root . '/admin/categories.php');
check('admin categories stores name/description/image through the policy, image never raw',
    strpos($admCatSrc, 'escapestring(ef_category_name(') !== false
        && strpos($admCatSrc, 'escapestring(ef_category_description(') !== false
        && strpos($admCatSrc, 'escapestring(ef_category_image(') !== false
        && strpos($admCatSrc, "\$_POST['image'].\"'") === false
        && strpos($admCatSrc, 'ef_category_label($cat1') !== false
        && strpos($admCatSrc, 'ef_category_h(stripslashes($cat1[\'description\']))') !== false);
check('admin category statements carry no raw request interpolation',
    strpos($admCatSrc, "\$_POST['parentcatid'].\"'") === false
        && strpos($admCatSrc, "\$_POST['catid'].\"'") === false
        && strpos($admCatSrc, "\$_POST['orderafter'].\"'") === false
        && strpos($admCatSrc, "'\$_POST[parentcatid]'") === false
        && strpos($admCatSrc, "'\$_GET[cat]'") === false
        && strpos($admCatSrc, "\$_GET['cat'].\"'") === false
        && strpos($admCatSrc, "relevelcategory(\$_GET['cat']") === false);
check('admin category edit refuses id mismatches and ghost parents, scopes shifts',
    strpos($admCatSrc, "\$movevalid = (\$catid > 0 && \$cat_id > 0 && \$catid === \$cat_id);") !== false
        && strpos($admCatSrc, "if(!\$parentresult || !isset(\$parentresult['leveldown'])) \$parentcatid = -1;") !== false
        && strpos($admCatSrc, "displayorder < \$displayorder AND parentcatid = '\$oldparent'") !== false
        && strpos($admCatSrc, "displayorder < \$oldorder AND parentcatid = '\$oldparent'") !== false
        && strpos($admCatSrc, "FIND_IN_SET('\$catid', catid)") !== false
        && strpos($admCatSrc, "FIND_IN_SET(catid, '\$catid')") === false);
$brwSrc = file_get_contents($root . '/includes/browsecategories.php');
check('browsecategories twin endpoint mirrors the 3.5.88 categorylist treatment',
    strpos($brwSrc, "header('X-Content-Type-Options: nosniff')") !== false
        && strpos($brwSrc, 'Content-Type: text/javascript') !== false
        && strpos($brwSrc, "preg_match('/^(?:[A-Za-z_][A-Za-z0-9_.-]*|[0-9]+)\$/D', \$_GET['element'])") !== false
        && strpos($brwSrc, 'ef_category_json($element)') !== false
        && strpos($brwSrc, "escapestring(\$sitekey)") !== false
        && strpos($brwSrc, "echo \"var el = '\".\$_GET['element']") === false
        && strpos($brwSrc, 'urlencode(ef_category_name(stripslashes(') !== false
        && strpos($brwSrc, 'urlencode(ef_category_utf8(') === false
        && strpos($brwSrc, 'htmlentities(str_replace($find') === false
        && strpos($brwSrc, "addslashes(ef_category_name(stripslashes((string)\$char['charname'])))") !== false
        && strpos($brwSrc, "SHOW COLUMNS FROM ") !== false
        && strpos($brwSrc, "\$action = '';") !== false
        && strpos($brwSrc, "preg_match('/^[A-Za-z0-9_-]+\$/D', \$settings['language'])") !== false);
$pgsSrc = file_get_contents($root . '/includes/pagesetup.php');
check('pagesetup global category, character and class lists render through the policy',
    strpos($pgsSrc, "ef_category_label(\$cat['category'])") !== false
        && strpos($pgsSrc, 'stripslashes($cat[\'category\'])') === false
        && strpos($pgsSrc, "ef_category_h(ef_category_name(stripslashes(\$char['charname'])))") !== false
        && strpos($pgsSrc, "ef_category_h(ef_category_name(stripslashes(\$class['class_name'])))") !== false
        && strpos($pgsSrc, 'category_helpers.php') !== false);
check('category admin delete path uses array_diff',
    strpos($admCatSrc, 'array_diff($cats, array($catid))') !== false
        && strpos($admCatSrc, 'array_dif(') === false);
check('category utf8 fast path is byte-identical under UTF-8',
    ef_category_utf8("caf\xC3\xA9") === "caf\xC3\xA9");
check('category utf8 converts legacy charset labels',
    shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -r ' . escapeshellarg('define("_CHARSET", "ISO-8859-1"); require $argv[1]."/includes/category_helpers.php"; echo ef_category_utf8("caf\xE9");') . ' ' . escapeshellarg($root))
        === "caf\xC3\xA9");
$blkCatSrc = file_get_contents($root . '/blocks/categories/categories.php');
$brwCatSrc = file_get_contents($root . '/browse/categories.php');
check('categories block and browse render through the policy',
    strpos($blkCatSrc, 'ef_category_label($categories[\'category\'])') !== false
        && strpos($blkCatSrc, 'ef_category_description(stripslashes($categories[\'description\']))') !== false
        && strpos($brwCatSrc, 'ef_category_label($cats[\'category\'])') !== false
        && strpos($brwCatSrc, 'ef_category_h("$skindir/images/".$cats[\'image\'])') !== false
        && strpos($brwCatSrc, 'ef_category_description(stripslashes($cats[\'description\']))') !== false);
$revresSrc = file_get_contents($root . '/user/revres.php');
check('review responses require the session token (3.5.83 port)',
    strpos($revresSrc, 'ef_csrf_verify()') !== false
        && strpos($revresSrc, 'ef_csrf_field( )') !== false);

// Item 63 (upstream efiction3-mod issue #11): the series rating UPDATE
// must gate on the rating aggregate, not the text-review count, or
// rate-only submissions never count toward the series rating.
$seriesreviewsSrc = file_get_contents($root . '/includes/seriesreviews.php');
check('series rating update gates on the rating aggregate',
    strpos($seriesreviewsSrc, "if(\$totalreviews !== null)") !== false
        && strpos($seriesreviewsSrc, "if(\$totalcount) \$update") === false);

check('bridge lost-password never seeds mt_srand',
    strpos($bridgeLost, 'mt_srand') === false);

// Mail encoding: PHPMailer 6.12 (vendored, 3.5.7 port) encodeFile runs on
// PHP 8 — the old 5.x bundle called get/set_magic_quotes_runtime.
require_once $root . '/includes/phpmailer/PHPMailer-6.12.0/src/PHPMailer.php';
$tmp = tempnam(sys_get_temp_dir(), 'efmail');
$payload = "attachment payload \xC3\xA4\n";
file_put_contents($tmp, $payload);
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$m = new ReflectionMethod('PHPMailer\PHPMailer\PHPMailer', 'encodeFile');
$m->setAccessible(true);
$encoded = $m->invoke($mail, $tmp, 'base64');
check('phpmailer encodeFile runs on php 8', base64_decode($encoded) === $payload);
unlink($tmp);
$pm = file_get_contents($root . '/includes/phpmailer/PHPMailer-6.12.0/src/PHPMailer.php');
check('phpmailer has no removed magic-quote calls', strpos($pm, 'get_magic_quotes_runtime') === false && strpos($pm, 'set_magic_quotes_runtime') === false);
check('phpmailer is the vendored 6.12.0 release', strpos(file_get_contents($root . '/includes/phpmailer/PHPMailer-6.12.0/VERSION'), '6.12.0') !== false);

if ($fails) {
    fwrite(STDERR, "$fails failure(s)\n");
    exit(1);
}
echo "runtime ok\n";
