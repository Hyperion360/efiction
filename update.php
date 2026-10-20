<?php
// ----------------------------------------------------------------------
// eFiction 3.0
// Copyright (c) 2007 by Tammy Keefer
// Valid HTML 4.01 Transitional
// Based on eFiction 1.1
// Copyright (C) 2003 by Rebecca Smallwood.
// http://efiction.sourceforge.net/
// ----------------------------------------------------------------------
// LICENSE
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License (GPL)
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// To read the license please visit http://www.gnu.org/copyleft/gpl.html
// ----------------------------------------------------------------------
$current = "update";

include("header.php");

$blocks['news']['status'] = 0;
$blocks['info']['status'] = 0;

//make a new TemplatePower object
if(file_exists("$skindir/default.tpl")) $tpl = new TemplatePower( "$skindir/default.tpl" );
else $tpl = new TemplatePower("default_tpls/default.tpl");
include("includes/pagesetup.php");
if(file_exists("languages/".$language."_admin.php")) include_once("languages/".$language."_admin.php");
else include_once("languages/en_admin.php");
// end basic page setup

if(!isADMIN) {
$output .= "<script language=\"javascript\" type=\"text/javascript\">
location = \"maintenance.php\";
</script>";
$tpl->assign( "output", $output );
$tpl->printToScreen();
dbclose( );
exit( );
}
// Maintenance fork: this updater performs no automatic database changes. The
// fork is file compatible with eFiction 3.5.5 and official 3.5.7 databases;
// anything older must reach a verified 3.5.5 copy first. The one schema step
// the fork can ever need — widening the password column for password_hash() —
// applies itself automatically at the first password write; the tokened form
// below lets the main administrator apply it up front (idempotent).
if(isset($_POST['widen']) && defined("uLEVEL") && uLEVEL == 1 && ef_csrf_verify()) {
	// Adversarial-review finding: the one mutation this page performs must
	// not fail silently (a denied ALTER on shared hosting re-shows the form
	// with no explanation).
	if(ef_ensure_password_column()) {
		$output .= "<div><p>The password column is now varchar(255).</p></div>";
	}
	else {
		$output .= "<div><p>The host refused the column widening (the database user may lack ALTER privilege, or MySQL rejected the rebuild). The fork stays in working MD5 mode; no hash was written. You can retry, apply install/upgrade_modern_passwords.sql by hand, or ask your host to widen fanfiction_authors.password to varchar(255).</p></div>";
	}
}
if(defined("uLEVEL") && uLEVEL == 1) {
	$plen = ef_password_column_length();
	if($plen !== false && $plen < 255) {
		$output .= "<div><form method=\"post\" action=\"update.php\">"
			. "<p>This archive's password column is still varchar($plen). It will be widened automatically at the first password write; you can also widen it now (idempotent).</p>"
			. ef_csrf_field() . "<input type=\"hidden\" name=\"widen\" value=\"1\" />"
			. "<input type=\"submit\" value=\"Widen password column\" /></form></div>";
	}
}
$settingsVersion = isset($settings['version']) ? (string) $settings['version'] : "";
if(version_compare($settingsVersion, "3.5.5", ">=")) {
	$output .= write_message("This archive reports version ".htmlspecialchars($settingsVersion, ENT_QUOTES, 'UTF-8').". The maintenance fork runs on any official 3.5.x database, including 3.5.7 (existing bcrypt hashes verify unchanged, legacy MD5 hashes upgrade transparently at each member's next login). Upload the maintenance files over the existing files, preserving config.php and your uploaded stories, then reload your site.");
}
else {
	$output .= write_error("This archive reports version ".($settingsVersion !== "" ? "'".htmlspecialchars($settingsVersion, ENT_QUOTES, 'UTF-8')."'" : "unknown").". The maintenance fork updates only from eFiction 3.5.5. First bring the archive to a verified eFiction 3.5.5 copy (upstream stable source: https://github.com/eFiction/v3_stable), back up your files and database, and only then install the maintenance files. No in-place database upgrade from older versions is provided.");
}
$tpl->assign( "output", $output );
$tpl->printToScreen();
dbclose( );
?>