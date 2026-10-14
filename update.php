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
// Maintenance fork: this updater performs no database changes. The fork is
// file compatible with eFiction 3.5.5 (same schema); anything older must
// reach a verified 3.5.5 copy first. There is deliberately no GET or POST
// mutation left on this page.
$settingsVersion = isset($settings['version']) ? (string) $settings['version'] : "";
if(version_compare($settingsVersion, "3.5.5", ">=")) {
	$output .= write_message("This archive reports version ".htmlspecialchars($settingsVersion, ENT_QUOTES, 'UTF-8').". The maintenance fork needs no database update for 3.5.5 archives: upload the maintenance files over the existing files, preserving config.php and your uploaded stories, then reload your site. This page performs no writes.");
}
else {
	$output .= write_error("This archive reports version ".($settingsVersion !== "" ? "'".htmlspecialchars($settingsVersion, ENT_QUOTES, 'UTF-8')."'" : "unknown").". The maintenance fork updates only from eFiction 3.5.5. First bring the archive to a verified eFiction 3.5.5 copy (upstream stable source: https://github.com/eFiction/v3_stable), back up your files and database, and only then install the maintenance files. No in-place database upgrade from older versions is provided.");
}
$tpl->assign( "output", $output );
$tpl->printToScreen();
dbclose( );
?>