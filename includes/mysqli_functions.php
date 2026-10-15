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