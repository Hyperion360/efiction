<?php
/**
 * Database compatibility test for the eFiction maintenance fork.
 * Exercises the existing dbconnect/dbquery/dbassoc wrapper against a
 * disposable test database with strict mysqli error reporting.
 *
 * Required env: EF_DB_NAME must match ^efiction_test_[a-z0-9_]+$ so the test
 * can never run against a real archive. Optional: EF_DB_HOST, EF_DB_USER,
 * EF_DB_PASSWORD, EF_DB_PORT (the wrapper's dbconnect() keeps its historic
 * four-argument signature, so a nondefault port is connected by the test and
 * handed to the same global the db* functions use; dbquery and friends are
 * still exercised unchanged).
 *
 * Run: EF_DB_NAME=efiction_test_ci php tests/compat/database.php
 */
define('_BASEDIR', dirname(__DIR__, 2) . '/');
$name = getenv('EF_DB_NAME');
if (!is_string($name) || !preg_match('/^efiction_test_[a-z0-9_]+$/', $name)) {
    throw new RuntimeException('test database required: set EF_DB_NAME to a name matching ^efiction_test_[a-z0-9_]+$');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require _BASEDIR . 'includes/mysqli_functions.php';

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

if (getenv('EF_DB_PORT')) {
    // Test-only connection override for engines on a nondefault port. The
    // db* wrapper functions below run against this same global handle.
    $dbconnect = new mysqli(
        getenv('EF_DB_HOST') ?: '127.0.0.1',
        getenv('EF_DB_USER') ?: 'root',
        getenv('EF_DB_PASSWORD') ?: '',
        $name,
        (int) getenv('EF_DB_PORT')
    );
} else {
    $dbconnect = dbconnect(
        getenv('EF_DB_HOST') ?: '127.0.0.1',
        getenv('EF_DB_USER') ?: 'root',
        getenv('EF_DB_PASSWORD') ?: '',
        $name
    );
}

$table = 'ef_test_' . bin2hex(random_bytes(4));
try {
    dbquery("CREATE TABLE `$table` (id INT PRIMARY KEY, title VARCHAR(100))");

    // Apostrophe and Unicode round trip through the existing escapestring.
    dbquery("INSERT INTO `$table` VALUES (1, '" . escapestring("O'Reilly 雪") . "')");
    $row = dbassoc(dbquery("SELECT title FROM `$table` WHERE id = 1"));
    if ($row['title'] !== "O'Reilly 雪") throw new RuntimeException('round-trip failed: ' . var_export($row['title'], true));
    echo "ok: apostrophe and unicode round trip\n";

    // NULL and empty string values must survive the wrapper unchanged.
    dbquery("INSERT INTO `$table` VALUES (2, NULL)");
    dbquery("INSERT INTO `$table` VALUES (3, '')");
    $row = dbassoc(dbquery("SELECT title FROM `$table` WHERE id = 2"));
    if ($row['title'] !== null) throw new RuntimeException('null round-trip failed: ' . var_export($row['title'], true));
    echo "ok: null value round trip\n";
    $row = dbassoc(dbquery("SELECT title FROM `$table` WHERE id = 3"));
    if ($row['title'] !== '') throw new RuntimeException('empty string round-trip failed: ' . var_export($row['title'], true));
    echo "ok: empty string round trip\n";

    // A failing query must not leak the SQL text to non-admin output.
    // dbquery routes failures to accessDenied, which prints a generic
    // message to guests and the query detail only to admins.
    $leaked = false;
    ob_start();
    try {
        dbquery("SELECT * FROM `$table` WHERE nope = 'admin-only-detail-xyz'");
    } catch (Throwable $e) {
        // Under strict reporting an uncaught throw would reach the guest;
        // dbquery must catch it first.
        $leaked = strpos($e->getMessage(), 'admin-only-detail-xyz') !== false;
    }
    $out = ob_get_clean();
    if ($leaked || strpos($out, 'admin-only-detail-xyz') !== false) {
        throw new RuntimeException('query error leaked non-admin output');
    }
    echo "ok: failing query does not leak SQL to guests\n";

    // connection failure path: useful error, no credential echo
    try {
        $bad = @new mysqli('127.0.0.1', 'root', 'definitely-not-the-password', $name, 1);
        $bad->close();
        echo "FAIL: connection failure case unreachable (a server accepted the bad login)\n";
        $fails++;
    } catch (mysqli_sql_exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'definitely-not-the-password') !== false) {
            echo "FAIL: connect error echoes the password\n";
            $fails++;
        } else {
            echo "ok: connect failure does not echo credentials\n";
        }
    }
    // ef_ensure_password_column: widens password to varchar(255), is
    // idempotent, and keeps the connection usable afterwards. The harness
    // database has no fanfiction_authors table, so a minimal one with the
    // legacy narrow column is created here.
    if (!defined('TABLEPREFIX')) define('TABLEPREFIX', '');
    dbquery("DROP TABLE IF EXISTS `fanfiction_authors`");
    dbquery("CREATE TABLE `fanfiction_authors` (`uid` INT PRIMARY KEY AUTO_INCREMENT, `password` varchar(40) NOT NULL default '')");
    $first = ef_ensure_password_column();
    $res = mysqli_query($dbconnect, "SELECT CHARACTER_MAXIMUM_LENGTH AS l FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fanfiction_authors' AND COLUMN_NAME = 'password'");
    $len = $res ? (string) mysqli_fetch_assoc($res)['l'] : '';
    $second = ef_ensure_password_column(); // must be a harmless no-op
    if ($first === true && $len === '255' && $second === true) {
        echo "ok: ef_ensure_password_column widens then is idempotent\n";
    } else {
        echo "FAIL: ef_ensure_password_column (first=" . var_export($first, true) . " len=$len second=" . var_export($second, true) . ")\n";
        $fails++;
    }
    dbquery("DROP TABLE `fanfiction_authors`");

} finally {
    dbquery("DROP TABLE IF EXISTS `$table`");
    dbclose();
}

if ($fails) {
    fwrite(STDERR, "$fails failure(s)\n");
    exit(1);
}
echo "database ok\n";
