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

// Mail encoding: PHPMailer encodeFile must run on PHP 8 where
// get/set_magic_quotes_runtime no longer exist.
require_once $root . '/includes/class.phpmailer.php';
$tmp = tempnam(sys_get_temp_dir(), 'efmail');
$payload = "attachment payload \xC3\xA4\n";
file_put_contents($tmp, $payload);
$mail = new PHPMailer(true);
$m = new ReflectionMethod('PHPMailer', 'encodeFile');
$m->setAccessible(true);
$encoded = $m->invoke($mail, $tmp, 'base64');
check('phpmailer encodeFile runs on php 8', base64_decode($encoded) === $payload);
unlink($tmp);
$pm = file_get_contents($root . '/includes/class.phpmailer.php');
check('phpmailer has no removed magic-quote calls', strpos($pm, 'get_magic_quotes_runtime') === false && strpos($pm, 'set_magic_quotes_runtime') === false);

if ($fails) {
    fwrite(STDERR, "$fails failure(s)\n");
    exit(1);
}
echo "runtime ok\n";
