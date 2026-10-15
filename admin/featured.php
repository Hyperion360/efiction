<?php

// ----------------------------------------------------------------------
// eFiction 3.2
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

if(!defined("_CHARSET")) exit( );

// Maintenance fork: the mutation executes only from a tokened POST (verified
// centrally in admin.php); a GET renders the question or nothing.
	if(isset($_POST['retire']) && isNumber($_POST['retire']))
		dbquery("UPDATE ".TABLEPREFIX."fanfiction_stories SET featured = 2 WHERE sid = ".$_POST['retire']);
	if(isset($_POST['remove']) && isNumber($_POST['remove']))
		dbquery("UPDATE ".TABLEPREFIX."fanfiction_stories SET featured = 0 WHERE sid = ".$_POST['remove']);
	if(isset($_POST['feature']) && isNumber($_POST['feature']))
		dbquery("UPDATE ".TABLEPREFIX."fanfiction_stories SET featured = 1 WHERE sid = ".$_POST['feature']);
	$fresult = dbquery("SELECT stories.*, stories.title as title, "._PENNAMEFIELD." as penname, UNIX_TIMESTAMP(stories.updated) as updated, UNIX_TIMESTAMP(stories.date) as date FROM "._AUTHORTABLE.", ".TABLEPREFIX."fanfiction_stories as stories WHERE stories.featured > 0 AND stories.uid = "._UIDFIELD." ORDER BY stories.featured");
	$output .= "<div id='pagetitle'>"._FEATUREDSTORIES."</div>";
	if(!dbnumrows($fresult)) $output .= write_message(_NORESULTS);
	else {
		$output .= "<table class=\"tblborder\" cellpadding=\"5\" style=\"margin: 1em auto;\"><tr><th class=\"tblborder\">"._STORIES."</th><th class=\"tblborder\">"._STATUS."</th><th class=\"tblborder\">"._OPTIONS."</th></tr>";
		while($story = dbassoc($fresult)) {
			$output .= "<tr><td class=\"tblborder\"><a href=\"viewstory.php?sid=$story[sid]\">$story[title]</a> "._BY." <a href=\"viewuser.php?uid=$story[uid]\">$story[penname]</a></td><td class=\"tblborder\" align=\"center\">".($story['featured'] == 1 ? _ACTIVE : _RETIRED)."</td><td class=\"tblborder\" align=\"center\">".($story['featured'] == 1 ? ef_csrf_post_link("admin.php?action=featured", _RETIRE, array('retire' => $story['sid'])) : ef_csrf_post_link("admin.php?action=featured", _CURRENT, array('feature' => $story['sid'])))." | ".ef_csrf_post_link("admin.php?action=featured", _REMOVE, array('remove' => $story['sid']))."</td></tr>";
		}
		$output .= "</table>";
		$output .= write_message(_FEATUREDNOTE);
	}
?>