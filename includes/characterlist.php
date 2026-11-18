<?php
define("_BASEDIR", "../");
include("../config.php");
include("../includes/dbfunctions.php");
// Maintenance fork (twin treatment): the settings read escapes the sitekey,
// the language constant is validated against the filesystem, and the
// settings the language file interpolates are supplied before it loads
// ($action included) - the character picker emitted the same PHP 8 notice
// into its JavaScript stream the dropdown endpoints did, and served its
// output as text/html.
$settings = dbassoc(dbquery("SELECT * FROM ".$settingsprefix."fanfiction_settings WHERE sitekey = '".escapestring($sitekey)."'"));
$tableprefix = isset($settings['tableprefix']) ? $settings['tableprefix'] : '';
define("TABLEPREFIX", $tableprefix);
$lang = isset($settings['language']) && is_string($settings['language']) && preg_match('/^[A-Za-z0-9_-]+$/D', $settings['language']) ? $settings['language'] : 'en';
if(!is_file(_BASEDIR."languages/$lang.php")) $lang = 'en';
foreach(array('allowed_tags', 'recentdays', 'sitename', 'url', 'multiplecats', 'minwords', 'maxwords', 'pwdsetting', 'imagewidth', 'imageheight') as $name) {
	if(isset($settings[$name])) $$name = stripslashes((string)$settings[$name]);
}
$action = '';
include(_BASEDIR."languages/$lang.php");
header("Content-Type: text/javascript; charset="._CHARSET,true);
header('X-Content-Type-Options: nosniff');
include("../includes/queries.php");
// Checks that the given $num is actually a number.  Used to help prevent XSS attacks.
function isNumber($num) {
	if(empty($num)) return false;
	return (preg_match("/^[0-9]+$/", $num) || $num == "-1" ? true : false);
}

$catid = isset($_GET['catid']) ? explode(",", $_GET['catid']) : array(-1);
$catid = array_filter($catid, "isNumber");
$characters = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_characters WHERE ".(count($catid) > 1 ? "FIND_IN_SET(catid, '".implode(",", $catid)."') > 0" : "catid = '".$catid[0]."'")." ORDER BY charname");
// Maintenance fork (3.5.84 port, categorylist sibling): the element name
// is reflected into JavaScript, so it must be a plain identifier and
// escaped; a missing element no longer emits a PHP 8 warning into the JS.
if(!isset($_GET['element']) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $_GET['element'])) $_GET['element'] = '';
echo "var element = '".addslashes($_GET['element'])."';\n";
$x = 0;
$find = array ('"', chr(150), chr(147), chr(148), chr(146));
$replace = array ('\"', "-", "\"", "\"", "'");
while($char = dbassoc($characters)) {
	echo "characters[$x] = new Array(".$char['charid'].", \"".urlencode(str_replace($find, $replace, stripslashes($char['charname'])))."\");\r\n";
	$x++;
}
?>