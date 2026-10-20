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

	if(isset($_POST['submit']) && preg_match("!^[-a-z0-9_ ]{3,30}$!i", $_POST['penname'])) {
		if(!defined("_LOGINCHECK")) exit( );
		define("_BASEDIR", "");
		include_once("config.php");
		$settings = dbquery("SELECT tableprefix, maintenance, sitekey, debug FROM ".$settingsprefix."fanfiction_settings WHERE sitekey = '".$sitekey."'");
		list($tableprefix, $maintenance, $sitekey, $debug) = dbrow($settings);
		$tempdebug = $debug;
		$debug = 0;
		define("TABLEPREFIX", $tableprefix);
		define("SITEKEY", $sitekey);
		include_once("includes/queries.php");
		// Maintenance fork: brute-force throttle on the existing log table
		// (no schema change): ten or more failed logins from this address in
		// the last fifteen minutes lock the form for fifteen minutes. The
		// count keys on ef_ip_log_key like the inserts below it (review
		// finding: INET_ATON is NULL for IPv6, leaving those clients
		// unthrottled while their failure rows still accumulated).
		list($recentfails) = dbrow(dbquery("SELECT COUNT(*) FROM ".TABLEPREFIX."fanfiction_log WHERE log_type = 'LF' AND log_ip = ".ef_ip_log_key($_SERVER['REMOTE_ADDR'])." AND log_timestamp > (NOW() - INTERVAL 15 MINUTE)"));
		if($recentfails >= 10) {
			require_once("header.php");
			if(file_exists("$skindir/default.tpl")) $tpl = new TemplatePower( "$skindir/default.tpl" );
			else $tpl = new TemplatePower("default_tpls/default.tpl");
			include_once("includes/pagesetup.php");
			$output = write_error("Too many failed sign-in attempts from this address. Try again in fifteen minutes.");
			$tpl->assign("output", $output);
			$tpl->printToScreen( );
			dbclose( );
			exit( );
		}
		$result = dbquery("SELECT *, "._UIDFIELD." as uid FROM "._AUTHORTABLE." LEFT JOIN ".$tableprefix."fanfiction_authorprefs AS ap ON ap.uid = "._UIDFIELD." WHERE "._PENNAMEFIELD." = '".$_POST['penname']."'");
		$passwd = dbassoc($result);
		if(!dbnumrows($result)) {
			// Maintenance fork: failed attempts are counted for the throttle.
			dbquery("INSERT INTO ".TABLEPREFIX."fanfiction_log (`log_action`, `log_uid`, `log_ip`, `log_type`, `log_timestamp`) VALUES('failed login', '0', ".ef_ip_log_key($_SERVER['REMOTE_ADDR']).", 'LF', NOW())");
			require_once("header.php");
			//make a new TemplatePower object
			if(file_exists("$skindir/default.tpl")) $tpl = new TemplatePower( "$skindir/default.tpl" );
			else $tpl = new TemplatePower("default_tpls/default.tpl");
			include_once("includes/pagesetup.php");
			$output = write_error(_NOSUCHACCOUNT);
			$tpl->assign("output", $output);
			$tpl->printToScreen( );
			dbclose( );
			exit( );
		}
		if($maintenance && $passwd['level'] < 0) {
			header("Location: maintenance.php");
			exit( );
		}
		// Maintenance fork (3.5.7 port): the stored row may be a bcrypt hash
		// or a legacy 32-hex md5; ef_verify_password adjudicates either. On a
		// legacy match the row is upgraded transparently (once the column is
		// widened) and the session/cookie salt derives from the POST-UPGRADE
		// stored hash, so remember-me survives its own upgrade login.
		$storedpassword = $passwd['password'];
		if($passwd['level'] == -1) {
			require_once("header.php");
			//make a new TemplatePower object
			if(file_exists("$skindir/default.tpl")) $tpl = new TemplatePower( "$skindir/default.tpl" );
			else $tpl = new TemplatePower("default_tpls/default.tpl");
			include_once("includes/pagesetup.php");
			$output = write_error(_ACCOUNTLOCKED);
			$tpl->assign("output", $output);
			$tpl->printToScreen( );
			dbclose( );
			exit( );
		}
		if(ef_verify_password($_POST['password'], $storedpassword)) {
			// Maintenance fork (3.5.7 port): transparent md5-to-bcrypt upgrade
			// on the first successful login; if the column cannot be widened
			// the row keeps its valid md5 (never a truncated bcrypt).
			if(ef_password_needs_upgrade($storedpassword) && ef_ensure_password_column()) {
				$newhash = ef_hash_password($_POST['password']);
				dbquery("UPDATE ".TABLEPREFIX."fanfiction_authors SET password = '".escapestring($newhash)."' WHERE uid = '".$passwd['uid']."'");
				$storedpassword = $newhash;
			}
			$salt = ef_session_salt($passwd['email'], $storedpassword);
			if(isset($_POST['cookiecheck'])) {
				// Maintenance fork: httponly and Lax on the remember-me pair,
				// matching the session cookie.
				setcookie($sitekey."_useruid", $passwd['uid'], array('expires' => time()+60*60*24*30, 'path' => "/", 'httponly' => true, 'samesite' => 'Lax'));
				setcookie($sitekey."_salt", $salt, array('expires' => time()+60*60*24*30, 'path' => "/", 'httponly' => true, 'samesite' => 'Lax'));
			}
			if(!isset($_SESSION)) {
				// Maintenance fork: match header.php's httponly/Lax session cookie.
				ini_set('session.cookie_httponly', '1');
				ini_set('session.cookie_samesite', 'Lax');
				session_start( );
			}
			// Maintenance fork: the pre-authentication session id is replaced
			// on promotion so a planted session id cannot survive login.
			session_regenerate_id(true);
			$_SESSION[$sitekey."_useruid"] = $passwd['uid'];
			$_SESSION[$sitekey."_salt"] = $salt;
			$logincode = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_codeblocks WHERE code_type = 'login'");
			while($code = dbassoc($logincode)) {
				eval($code['code_text']);
			}
		}

		else {
			// Maintenance fork: failed attempts are counted for the throttle.
			dbquery("INSERT INTO ".TABLEPREFIX."fanfiction_log (`log_action`, `log_uid`, `log_ip`, `log_type`, `log_timestamp`) VALUES('failed login', '0', ".ef_ip_log_key($_SERVER['REMOTE_ADDR']).", 'LF', NOW())");
			require_once("header.php");
			//make a new TemplatePower object
			if(file_exists("$skindir/default.tpl")) $tpl = new TemplatePower( "$skindir/default.tpl" );
			else $tpl = new TemplatePower("default_tpls/default.tpl");
			include_once("includes/pagesetup.php");
			$output .= "<div id=\"pagetitle\">"._MEMBERLOGIN."</div>";
			$output .= "<div style='text-align: center;'>"._WRONGPASSWORD."</div>";
			$tpl->assign("output", $output);
			$tpl->printToScreen( );
			dbclose( );
			exit( );
		}
		$debug = $tempdebug;
	}
	else if(!isMEMBER) {
		$output .= "<div id=\"pagetitle\">"._MEMBERLOGIN."</div>";
		$output .= "<div style=\"width: 250px; margin: 0 auto; text-align: center;\"><form method=\"POST\" enctype=\"multipart/form-data\" action=\"user.php?action=login".(isset($_GET['sid']) && isNumber($_GET['sid']) ? "&amp;sid=".$_GET['sid'] : "")."\">
		<div class=\"label\" style=\"float: left;  width: 30%; text-align: right;\"><label for=\"penname\">"._PENNAME.":</label></div><INPUT type=\"text\" class=\"textbox\" name=\"penname\" id=\"penname\"><br />
		<div class=\"label\" style=\"float: left; width: 30%; text-align: right;\"><label for=\"pswd\">"._PASSWORD.":</label></div><INPUT type=\"password\" class=\"textbox\" id=\"pswd\" name=\"password\"><br />
		<INPUT type=\"checkbox\" class=\"checkbox\" name=\"cookiecheck\" id=\"cookiecheck\" value=\"1\"><label for=\"cookiecheck\">"._REMEMBERME."</label><br />
		<INPUT type=\"submit\" class=\"button\" name=\"submit\" value=\""._SUBMIT."\">
		</form></div>";
		$linkquery = dbquery("SELECT * from ".TABLEPREFIX."fanfiction_pagelinks WHERE link_name = 'login' OR link_name = 'lostpassword'");
		while($link = dbassoc($linkquery)) {
			if($link['link_access'] && !isMEMBER) continue;
			if($link['link_access'] == 2 && !isADMIN) continue;
			$pagelinks[$link['link_name']] = array("id" => $link['link_id'], "text" => $link['link_text'], "url" => _BASEDIR.$link['link_url'], "link" => "<a href=\"".$link['link_url']."\" title=\"".$link['link_text']."\"".($link['link_target'] ? " target=\"_blank\"" : "").($current == $link['link_name'] ? " id=\"current\"" : "").">".$link['link_text']."</a>");
		}
		$output .= "<div style='text-align: center;'>".$pagelinks['register']['link']." | ".$pagelinks['lostpassword']['link']."</div>";
	}
?>