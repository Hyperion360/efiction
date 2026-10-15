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

	if(uLEVEL > 1) accessDenied( );
	$do = $_GET["do"];
	// Maintenance fork: the unlock executes only from a tokened POST
	// (verified centrally in admin.php); a GET renders the question.
	if(ef_param("unlock") && isNumber(ef_param('unlock'))) {
		$output .= "<div id=\"pagetitle\">"._UNLOCK."</div>";
		if(isset($_POST["confirm"]) && $_POST["confirm"] == "yes")
		{
			dbquery("UPDATE ".TABLEPREFIX."fanfiction_authors SET level = '0' WHERE uid = '".ef_param('unlock')."'");
			$output .= "<center>"._ACTIONSUCCESSFUL."</center>";
		}
		else if (isset($_POST["confirm"]) && $_POST["confirm"] == "no")
		{
			$output .= "<center>"._ACTIONCANCELLED."</center>";
		}
		else
		{
			$output .= "<div id=\"pagetitle\">"._CONFIRMUNLOCK."</div>";
			$output .= "<center>[ ".ef_csrf_post_link("admin.php?action=lock", _YES, array('confirm' => 'yes', 'unlock' => ef_param('unlock')))." | <a href=\"admin.php?action=lock\">"._NO."</a> ]</center>";
		}

	}

	else if(isset($do)) {
	
		if($_POST['submit'] && isNumber($_POST['uid'])) {
			dbquery("UPDATE ".TABLEPREFIX."fanfiction_authors SET level = '-1' WHERE uid = '".$_POST['uid']."'");
			
			$output .= "<center>"._BACK2ADMIN."</center>";
			return $output;
		}

		$action = $_GET['action'];
		if(isset($_GET["uid"])) $uid = $_GET["uid"];
		else $uid = $_POST["uid"];
		if(!isNumber($uid)) $uid = 0;
		$output .= "<div id=\"pagetitle\">"._MEMLOCK."</div><form method=\"POST\" name=\"form\" enctype=\"multipart/form-data\" action=\"/admin.php?action=lock&do=$do\">";
		$output .= "<table align=\"center\" width=\"250\" class=\"tblborder\"><tr><td><label for=\"uid\">"._PENNAME.": </label>";
			$query = dbquery("SELECT penname, uid FROM ".TABLEPREFIX."fanfiction_authors WHERE level = '0' ORDER BY penname");
			$output .= "<select name=\"uid\">";
			while($users = dbassoc($query)) {
				$output .= "<option value=\"".$users['uid']."\"".($_GET['uid'] == $users['uid'] ? " selected" : "").">".$users['penname']."</option>";
			}	
		
			$output .= "</select></td></tr><tr align\"center\"><td colspan=\"2\">
			<INPUT type=\"submit\" class=\"button\" value=\""._SUBMIT."\" name=\"submit\">
			</form></td></tr></table>";	
	}
	
	else {

		if($let == _OTHER) $letter= _PENNAMEFIELD." REGEXP '^[^a-z]'";
		else $letter .= _PENNAMEFIELD." LIKE '".$_GET['let']."%'";
		$output .= "<div class='sectionheader'>"._MEMLOCK."</div>";
		$pagelink = "<a href=\"admin.php?action=lock&let=";
		$countquery = "SELECT count("._UIDFIELD.") FROM "._AUTHORTABLE." LEFT JOIN ".TABLEPREFIX."fanfiction_authorprefs as ap ON ap.uid = "._UIDFIELD." WHERE ap.level = -1";
		$authorquery = "SELECT "._PENNAMEFIELD." as penname, "._UIDFIELD." as uid, count(stories.uid) as stories FROM "._AUTHORTABLE." LEFT JOIN ".TABLEPREFIX."fanfiction_stories as stories ON ("._UIDFIELD." = stories.uid) AND "._UIDFIELD." > 0 AND stories.validated > 0 LEFT JOIN ".TABLEPREFIX."fanfiction_authorprefs as ap ON ap.uid = "._UIDFIELD." WHERE ap.level = -1 GROUP BY "._UIDFIELD;
		include("includes/members_list.php");

	
		$output .= "<div class=\"respond\"><a href=\"admin.php?action=lock&do=new\">"._LOCKNEW."</a></div>";
	}

?>