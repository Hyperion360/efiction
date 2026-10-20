<?php

// Some DB functions 
if(!function_exists("dbconnect")) { // just in case.  Some people seemed to be having an issue and this was the easiest fix.

function dbconnect($dbhost, $dbuser, $dbpass, $dbname ) {
	$mysql_access = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
	if(mysqli_connect_error()) {
		include(_BASEDIR."languages/en.php"); // Because we haven't got a language set yet.
		die(_FATALERROR." "._NOTCONNECTED);
	}
	// Maintenance fork: upstream set SESSION sql_mode = 'MYSQL40' here to
	// recreate pre-4.1 behavior. MySQL 8.0 and current MariaDB removed that
	// mode, so the statement failed every connection. The server default
	// mode is kept; no permissive global mode is set instead.
	return $mysql_access;
}


// Maintenance fork: a numeric per-address key for the log-based rate
// limits. INET_ATON is NULL for IPv6 addresses, which left throttles inert
// for IPv6 clients; IPv4 keeps the INET_ATON-compatible value and IPv6
// falls back to a CRC32 key. This file loads before corefunctions in the
// early bootstrap (login.php), so the helper lives here.
if(!function_exists('ef_ip_log_key')) {
function ef_ip_log_key($ip) {
	$ip = (string) $ip;
	if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return sprintf('%u', ip2long($ip));
	return sprintf('%u', crc32($ip));
}
}

// Maintenance fork (3.5.7 port): the password helpers canonically live in
// corefunctions.php (same guarded seam as the CSRF helpers, so the installer
// inherits them). user/login.php's early bootstrap runs BEFORE corefunctions
// is loaded, though, so - like ef_ip_log_key above - the guarded definitions
// are repeated here. function_exists guards mean only whichever file loads
// first defines them; the bodies are identical.
if(!function_exists("ef_hash_password")) {
function ef_hash_password($plain) {
	// A non-string plain value (crafted password[] POST fields) must never
	// reach md5()/password_hash(), which TypeError on arrays.
	if(!is_string($plain)) $plain = '';
	return password_hash($plain, PASSWORD_DEFAULT);
}
}
if(!function_exists("ef_password_needs_upgrade")) {
function ef_password_needs_upgrade($stored) {
	return is_string($stored) && strlen($stored) === 32 && ctype_xdigit($stored);
}
}
if(!function_exists("ef_verify_password")) {
function ef_verify_password($plain, $stored) {
	if(!is_string($plain)) $plain = '';
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
if(!function_exists("ef_native_authortable")) {
// True only when _AUTHORTABLE is eFiction's own fanfiction_authors table
// (bodies identical to the corefunctions copy; see the note above).
function ef_native_authortable() {
	if(!defined("_AUTHORTABLE") || !defined("TABLEPREFIX")) return false;
	return strpos(_AUTHORTABLE, TABLEPREFIX."fanfiction_authors") === 0;
}
}

if(!function_exists("ef_password_column_length")) {
	// Returns the password column's current width, or false when it cannot
	// be read (hidden INFORMATION_SCHEMA, early bootstrap). Used by
	// update.php's admin form.
	function ef_password_column_length() {
		global $dbconnect;
		if(!$dbconnect) return false;
		try {
			$result = $dbconnect->query("SELECT CHARACTER_MAXIMUM_LENGTH AS l FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = '".TABLEPREFIX."fanfiction_authors' AND COLUMN_NAME = 'password'");
			$row = $result ? $result->fetch_assoc() : null;
			return ($row && isset($row['l'])) ? (int)$row['l'] : false;
		} catch(\mysqli_sql_exception $e) {
			return false;
		}
	}
}

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
				// eFiction's 3.5.x tables carry zero-date defaults (0000-00-00),
				// and a CHANGE COLUMN rebuild re-validates the whole definition:
				// on MySQL 8+/9.x strict mode (NO_ZERO_DATE in the default
				// sql_mode) the ALTER dies with "Invalid default value" before
				// touching privileges. MariaDB's default mode permits it. Relax
				// the session mode for this one statement and restore it after.
				$mode = $dbconnect->query("SELECT @@SESSION.sql_mode AS m")->fetch_assoc();
				$oldmode = is_array($mode) && isset($mode['m']) ? $mode['m'] : "";
				try {
					$dbconnect->query("SET SESSION sql_mode = ''");
					// Idempotent DDL: re-running CHANGE on an already-wide
					// column succeeds. The default stays '0', matching the
					// upstream 3.5.x sentinel for passwordless rows.
					$alt = $dbconnect->query("ALTER TABLE ".TABLEPREFIX."fanfiction_authors
						CHANGE `password` `password` varchar(255) NOT NULL default '0'");
					if($alt) $ok = true;
				} catch(\mysqli_sql_exception $inner) {
					// Denied ALTER even with the relaxed mode: stay in md5 mode.
				} finally {
					try { $dbconnect->query("SET SESSION sql_mode = '".$oldmode."'"); } catch(\mysqli_sql_exception $restore) { }
				}
			} catch(\mysqli_sql_exception $e) {
				// Unreadable INFORMATION_SCHEMA: stay in md5 mode.
			}
			return $ok;
		}
}

function dbquery($query) {
	global $debug, $headerSent, $dbconnect;
	if($debug && $headerSent) echo "<!-- $query -->\n";
	// Maintenance fork: since PHP 8.1 mysqli throws mysqli_sql_exception by
	// default, which bypassed the old "or accessDenied(...)" guard and could
	// surface the query text in a fatal error page. Failures now go through
	// accessDenied: guests see the generic message, admins see the query.
	try {
		$result = $dbconnect->query($query);
	} catch (mysqli_sql_exception $e) {
		// Maintenance fork: accessDenied lives in corefunctions, which early
		// bootstrappers (login.php, the database harness) have not loaded
		// yet; fail with a controlled, CATCHABLE Error carrying a 500
		// status. Throwing (not exiting) preserves the pre-guard contract:
		// callers that wrap dbquery in try/catch - like
		// tests/compat/database.php's leak probe - survive the failure and
		// assert no SQL text reached the output.
		if(!function_exists("accessDenied")) {
			if(!headers_sent()) http_response_code(500);
			throw new Error("A database error occurred before the error handler was loaded.");
		}
		accessDenied( _FATALERROR.(defined("isADMIN") && isADMIN ? "Query: ".$query."<br />Error: ".$e->getMessage() : ""));
		exit( );
	}
	// Maintenance fork: with mysqli error reporting disabled (or on PHP 8.0),
	// a failed query returns false instead of throwing; route that through
	// the same guard so no caller ever receives a false result.
	if($result === false) {
		if(!function_exists("accessDenied")) {
			if(!headers_sent()) http_response_code(500);
			throw new Error("A database error occurred before the error handler was loaded.");
		}
		accessDenied( _FATALERROR.(defined("isADMIN") && isADMIN ? "Query: ".$query : ""));
		exit( );
	}
	return $result;
}

function dbnumrows($query) {
	global $debug, $dbconnect;
	if ($query === false && $debug) {
		echo "<!-- dbnumrows ".$dbconnect->mysqli_error." -->\n";
	}
	return $query->num_rows;
}

function dbassoc($query) {
	global $debug, $dbconnect;
	if ($query === false && $debug) {
		echo "<!-- dbassoc ".$dbconnect->mysqli_error." -->\n";
	}
	return $query->fetch_assoc();
}

function dbinsertid($tablename = 0) {
	global $dbconnect;
	return $dbconnect->insert_id;
}

function dbrow($query) {
	global $debug, $dbconnect;
	if ($query === false && $debug) {
		if($error) echo "<!-- dbrow ".$dbconnect->mysqli_error." -->\n";
	}
	return $query->fetch_row();
}

function dbclose() {
	global $dbconnect;
	$dbconnect->close();
}

// Used to escape text being put into the database.
function escapestring($str) {
	global $dbconnect;
   if (!is_array($str)) return $dbconnect->real_escape_string($str);
   return array_map('escapestring', $str);
}

// End DB functions

}
?>