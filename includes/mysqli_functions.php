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
		accessDenied( _FATALERROR.(defined("isADMIN") && isADMIN ? "Query: ".$query."<br />Error: ".$e->getMessage() : ""));
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