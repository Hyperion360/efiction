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
if(!function_exists("random_char")) {

function random_char($string)
{
	$length = strlen($string);
	$position = mt_rand(0, $length - 1);
	return ($string[$position]);
}

function random_string ($charset_string, $length)
{
	$return_string = random_char($charset_string);
	for ($x = 1; $x < $length; $x++)
	$return_string .= random_char($charset_string);
	return $return_string;
}

}
if(isMEMBER) accessDenied( );
	$output = "<div id=\"pagetitle\">"._LOSTPASSWORD."</div>";

	  if(isset($_POST['submit'])) {
		if(validEmail($_POST['email'])) {
			// Maintenance fork: a rate limit on the existing LP log rows (no
			// schema change) - five or more resets from one address in an
			// hour stop the form.
			list($recentresets) = dbrow(dbquery("SELECT COUNT(*) FROM ".TABLEPREFIX."fanfiction_log WHERE log_type = 'LP' AND log_ip = ".ef_ip_log_key($_SERVER['REMOTE_ADDR'])." AND log_timestamp > (NOW() - INTERVAL 1 HOUR)"));
			$result = dbquery("SELECT "._UIDFIELD." as uid, "._PENNAMEFIELD." as penname, "._EMAILFIELD." AS email FROM "._AUTHORTABLE." WHERE email = '".strtolower(escapestring(descript($_POST['email'])))."'");
			list($uid, $penname, $email) = dbrow($result);
			// Maintenance fork: the response no longer distinguishes known
			// from unknown addresses (email enumeration); an unknown address
			// receives the same message and no mail.
			if($recentresets >= 5 || dbnumrows($result) == 0) $output .= write_message(_PASSWORDSENT);
			else {
				include("includes/emailer.php");
				// Maintenance fork: upstream seeded mt_rand from the request
				// microtime, making the reset password brute-forceable from
				// the seed window; selection is now crypto-random.
				$charset = '23456789' . 'abcdefghijkmnpqrstuvwxyz' . 'ABCDEFGHJKLMNPQRSTUVWXYZ';
				$pass = '';
				for($i = 0; $i < 10; $i++) $pass .= $charset[random_int(0, strlen($charset) - 1)];
				// Maintenance fork (3.5.7 port): bcrypt when the column allows,
				// valid legacy md5 when the host denies the widening ALTER.
				$encryppass = ef_ensure_password_column() ? ef_hash_password($pass) : md5($pass);
				$subject = _NEWPWDSUB;
				$mailtext = sprintf(_NEWPWDMSG, $pass);

		
				$result = sendemail($penname, $email, $sitename, $siteemail, $subject, $mailtext, "html");
				// Maintenance fork: the reset rate limit counts every mailed
				// reset, independent of the site logging setting.
				dbquery("INSERT INTO ".TABLEPREFIX."fanfiction_log (`log_action`, `log_uid`, `log_ip`, `log_type`, `log_timestamp`) VALUES('password reset', '".$uid."', ".ef_ip_log_key($_SERVER['REMOTE_ADDR']).", 'LP', NOW())");
				if($result) {
					$output .= write_message(_PASSWORDSENT);
					dbquery("UPDATE ".substr(_AUTHORTABLE, 0, strpos(_AUTHORTABLE, "as author"))." SET password='$encryppass' WHERE uid = '".$uid."'");
				}
				else $output .=  write_message(_EMAILFAILED);
				if($logging) 
					dbquery("INSERT INTO ".TABLEPREFIX."fanfiction_log (`log_action`, `log_uid`, `log_ip`, `log_type`) VALUES('".escapestring(sprintf(_LOG_LOST_PASSWORD, $penname, $uid, ($result ? _YES : _NO)))."', '$uid', INET_ATON('".$_SERVER['REMOTE_ADDR']."'), 'LP')");
			}

		}
		else $output .= write_message(_BADEMAIL);
	}
	else {
		$output .= "<form method=\"POST\" enctype=\"multipart/form-data\" action=\"user.php?action=lostpassword\">
		<table align=\"center\" width=\"300\">
		<tr><td>"._ENTEREMAIL."</td></tr>
		<tr><td><INPUT  type=\"text\" class=\"textbox=\" name=\"email\"> <INPUT type=\"submit\" class=\"button\" name=\"submit\" value=\""._SUBMIT."\"></form>
		</td></tr></table>";
	}

?>