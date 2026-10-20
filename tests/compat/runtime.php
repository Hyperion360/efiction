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
