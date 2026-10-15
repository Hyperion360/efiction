<?php
// ----------------------------------------------------------------------
// Copyright (c) 2007 by Tammy Keefer
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

$current = "adminarea";

if(isset($_GET['action']) && ($_GET['action'] == "categories" || $_GET['action'] == "admins")) $displayform = 1;

$disableTiny = true;

// Maintenance fork: panel deny paths (custom pages, messages) set the
// response status after header.php has already produced output; buffering
// keeps those status changes effective on hosts without whole-page output
// buffering (the installer solved the same problem the same way). The
// buffer callback also appends the session CSRF token to every form
// rendered in the admin area, so the central POST gate below can verify it
// no matter which panel rendered the form.
ob_start(function($html) {
	return preg_replace('(<form\b[^>]*>)i', '$0'.ef_csrf_field(), $html);
});

include ("header.php");

//make a new TemplatePower object
if(file_exists("$skindir/default.tpl")) $tpl = new TemplatePower( "$skindir/default.tpl" );
else $tpl = new TemplatePower("default_tpls/default.tpl");
include("includes/pagesetup.php");
if(file_exists("languages/".$language."_admin.php")) include_once("languages/".$language."_admin.php");
else include_once("languages/en_admin.php");
// end basic page setup

// check that user has permissions to perform this action before going further.  Otherwise kick 'em
	if(!isADMIN) accessDenied( );
	// Maintenance fork: every POST in the admin area carries the session
	// token (the buffer callback injects it into every rendered form); a
	// forged or tokenless POST is rejected before any panel runs.
	if($_SERVER['REQUEST_METHOD'] === 'POST' && !ef_csrf_verify()) {
		http_response_code(403);
		$output = write_error("Invalid or missing security token. Go back, reload the page, and try again.");
		$tpl->assign("output", $output);
		$tpl->printToScreen( );
		dbclose( );
		exit( );
	}
	$adminquery = dbquery("SELECT categories FROM ".TABLEPREFIX."fanfiction_authorprefs WHERE uid = '".USERUID."' LIMIT 1");
	list($admincats) = dbrow($adminquery);
	if(empty($admincats)) $admincats = "0";
	$output = "<div style='text-align: center; margin: 1em;'>";
	$panelquery = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_panels WHERE panel_hidden != '1' AND panel_type = 'A' AND panel_level >= ".uLEVEL." ORDER BY panel_level DESC, panel_order ASC, panel_title ASC");
	if(!dbnumrows($panelquery)) $output .= _FATALERROR;
	$panellist = array();
	while($panel = dbassoc($panelquery)) {
		if(!$panel['panel_url']) $panellist[$panel['panel_level']][]= "<a href=\"admin.php?action=".$panel['panel_name']."\">".$panel['panel_title']."</a>";
		else $panellist[$panel['panel_level']][] = "<a href=\"".$panel['panel_url']."\">".$panel['panel_title']."</a>";
	}
	foreach($panellist as $accesslevel => $row) {
		$output .= implode(" | ", $row)."<br />";
	}
	$output .= "</div>\n";
	if($action) {
		// Maintenance fork: $action arrives via strip_tags only, which keeps
		// quotes; escape it before it reaches the panel lookup.
		$panelquery = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_panels WHERE panel_name = '".escapestring($action)."' AND panel_type = 'A' LIMIT 1");
		if(dbnumrows($panelquery)) {
			$panel = dbassoc($panelquery);
			if((isset($panel['panel_level']) ? $panel['panel_level'] : 0) >= uLEVEL) {
				if($panel['panel_url'] && file_exists(_BASEDIR.$panel['panel_url'])) require_once(_BASEDIR.$panel['panel_url']);
				else if (file_exists("admin/{$action}.php")) require_once("admin/{$action}.php");
			}
			else accessDenied( );
		}
	}
	else {
		if (file_exists("install"))
			$output .= write_error(_SECURITYDELETE);
		$adminnotices = "";
		$countquery = dbquery("SELECT COUNT(DISTINCT chapid) FROM ".TABLEPREFIX."fanfiction_chapters WHERE validated < 1");
		list($count) = dbrow($countquery);
		if($count) $adminnotices .= write_message(sprintf(_QUEUECOUNT, $count));
		$codequery = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_codeblocks WHERE code_type = 'adminnotices'");
		while($code = dbassoc($codequery)) {
			eval($code['code_text']);
		}
		$output .= write_message($adminnotices);
		$output .= write_message(_RUNNINGVERSION);
		// The maintenance-fork notice: the fork ships repairs only, so the
		// admin home says once who maintains it and where the successor
		// lives. Static English, no queries, no language-file changes;
		// recorded in CHANGES-MAINTENANCE.md.
		$output .= write_message("This is a maintenance release of eFiction, fixed and maintained by Danilo Stern-Sapad of Hyperion360 (https://danilosapad.com/): the same archive, repaired for current PHP, with no new features. The same maintainer developed Kiption, the successor written from the ground up as a much better, faster alternative with richer features. <a href=\"https://github.com/Hyperion360/kiption#migrating-from-efiction\">Migrate to Kiption</a> when you want it; this archive imports in one command and Kiption is MIT licensed. Host it yourself for free, or let <a href=\"https://kiption.com/cloud\">Kiption Cloud</a> host it for you.");
	}	
	$tpl->assign( "output", $output );
	$tpl->printToScreen();
	dbclose( );
?>