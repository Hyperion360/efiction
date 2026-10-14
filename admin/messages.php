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

if(!defined("_CHARSET")) exit( );

// Maintenance fork: this editor interpolated $_GET['message'] straight into
// its queries and accepted forged writes. Writes now require a session
// bound token, and the message name is validated like every identifier.
$csrfKey = SITEKEY . '_messages_csrf';
if(empty($_SESSION[$csrfKey])) $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
if(isset($_POST['submit']) && (!isset($_POST['csrf']) || !hash_equals($_SESSION[$csrfKey], (string) $_POST['csrf']))) {
	http_response_code(403);
	exit('Forbidden');
}
$messageName = (isset($_GET['message']) && preg_match('/^[a-z0-9_]{3,30}$/i', (string) $_GET['message'])) ? (string) $_GET['message'] : "";
$efCsrfField = "<input type='hidden' name='csrf' value='".htmlspecialchars($_SESSION[$csrfKey], ENT_QUOTES, 'UTF-8')."'>";

	$output .= "<div id=\"pagetitle\">"._MESSAGESETTINGS."</div>";

if($messageName === "") {
	$output .= write_error(_BADVARNAME);
} else if(isset($_POST['submit'])) {
	$result = dbquery("UPDATE ".TABLEPREFIX."fanfiction_messages SET message_text = '".escapestring(descript($_POST['text']))."' WHERE message_name = '".escapestring($messageName)."' LIMIT 1");
	if($result) $output .= write_message(_ACTIONSUCCESSFUL);
	else $output .= write_error(_ERROR);
}
else {
	$pagequery = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_messages WHERE message_name = '".escapestring($messageName)."' LIMIT 1");
	$message =  dbassoc($pagequery);
	$text = $message['message_text'];
	if(!$message) dbquery("INSERT INTO `".TABLEPREFIX."fanfiction_messages` (`message_name` , `message_title` , `message_text` ) VALUES ('".escapestring($messageName)."', '', '')");
	$output .= "<div class='sectionheader'>".htmlspecialchars(preg_replace("@\{sitename\}@", (string) $sitename, (string) $message['message_title']), ENT_QUOTES, 'UTF-8')."</div>
		<div style='width: 100%;'><div  id=\"settingsform\"><form method=\"POST\" enctype=\"multipart/form-data\" action=\"admin.php?action=messages&message=".htmlspecialchars($messageName, ENT_QUOTES, 'UTF-8')."\">
		$efCsrfField
		<textarea rows=\"10\" cols=\"60\" style=\"width: 100%;\" ".($messageName == "tinyMCE" ? "class='mceNoEditor'" :"")." name=\"text\">$text</textarea>";
	if($tinyMCE && $messageName != "tinyMCE") 
		$output .= "<div class='tinytoggle'><input type='checkbox' name='toggle' onclick=\"toogleEditorMode('text');\" checked><label for='toggle'>"._TINYMCETOGGLE."</label></div>";
	$output .= "<div style='clear: both;'>&nbsp;</div><INPUT type='submit' class='button' id='submit' value='"._SUBMIT."' name='submit'>
				</form></div><div style='clear: both;'>&nbsp;</div></div>";
}
?>