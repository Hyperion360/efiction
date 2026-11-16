<?php
define("_BASEDIR", "../");
include("../config.php");
include("../includes/dbfunctions.php");
// Maintenance fork (twin of the 3.5.88 categorylist port): the settings read
// escapes the sitekey, the language constant is validated against the
// filesystem (its file defines _CHARSET for the header), the element
// reflection is validated and JSON-encoded, and the JavaScript content type
// is asserted with nosniff. This endpoint used to echo raw GET input into a
// default text/html response, which made reflected script execute
// unauthenticated.
$settings = dbassoc(dbquery("SELECT * FROM ".$settingsprefix."fanfiction_settings WHERE sitekey = '".escapestring($sitekey)."'"));
$tableprefix = isset($settings['tableprefix']) ? $settings['tableprefix'] : '';
define("TABLEPREFIX", $tableprefix);
$lang = isset($settings['language']) && is_string($settings['language']) && preg_match('/^[A-Za-z0-9_-]+$/D', $settings['language']) ? $settings['language'] : 'en';
if(!is_file(_BASEDIR."languages/$lang.php")) $lang = 'en';
// Supply the settings the language file interpolates before it loads, so no
// PHP 8 notice reaches the JavaScript stream; $action is interpolated by
// this fork's language file but is not a settings column.
foreach(array('allowed_tags', 'recentdays', 'sitename', 'url', 'multiplecats', 'minwords', 'maxwords', 'pwdsetting', 'imagewidth', 'imageheight') as $name) {
	if(isset($settings[$name])) $$name = stripslashes((string)$settings[$name]);
}
$action = '';
include(_BASEDIR."languages/$lang.php");
require_once __DIR__.'/category_helpers.php';
header("Content-Type: text/javascript; charset="._CHARSET,true);
header('X-Content-Type-Options: nosniff');
include("../includes/queries.php");

$catid = isset($_GET['catid']) && is_string($_GET['catid']) && preg_match("/^[0-9]+$/D", $_GET['catid']) ? $_GET['catid'] : -1;
// Maintenance fork (3.5.85 port): sort by the columns that exist instead of
// fataling on very old restores, the same probe the dropdown endpoint runs.
$ef_has = array();
$ef_cols = dbquery("SHOW COLUMNS FROM ".TABLEPREFIX."fanfiction_categories");
while($ef_c = dbassoc($ef_cols)) $ef_has[] = $ef_c['Field'];
$ef_order = (in_array('leveldown', $ef_has) && in_array('displayorder', $ef_has)) ? 'leveldown, displayorder' : 'catid';
$cats = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_categories WHERE parentcatid = '$catid' OR catid = '$catid' ORDER BY ".$ef_order);
$element = isset($_GET['element']) && is_string($_GET['element']) && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_.-]*|[0-9]+)$/D', $_GET['element']) ? $_GET['element'] : '';
echo "var el = ".ef_category_json($element).";\n";
$x = 0;
while($category = dbassoc($cats)) {
	// The browse form's picker URL-decodes these labels (unescape plus the
	// plus-to-space rule), which maps %XX to Latin-1 code points; keep the
	// bytes in the archive's own charset, the same contract as the
	// categorylist endpoint.
	$label = urlencode(ef_category_name(stripslashes((string)$category['category'])));
	echo "categories[$x] = new category(".(int)$category['parentcatid'].", ".(int)$category['catid'].", ".ef_category_json($label).", ".(int)$category['locked'].", ".(isset($category['displayorder']) ? (int)$category['displayorder'] : 0).");\r\n";
	$x++;
}
$characters = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_characters WHERE catid = '$catid' OR catid = '-1' ORDER BY charname");
$x = 0;
while($char = dbassoc($characters)) {
	// Character names are consumed as plain option text without URL decoding;
	// addslashes keeps the bytes byte-identical (the JSON encoder would mangle
	// the fork's ISO-8859-1 high bytes) while the quotes stay escaped inside
	// the eval'd string literal. Escape line breaks for that literal too.
	$charname = str_replace(array("\r", "\n"), array('\r', '\n'), addslashes(ef_category_name(stripslashes((string)$char['charname']))));
	echo "characters[$x] = new Array(".(int)$char['charid'].", \"".$charname."\");\r\n";
	$x++;
}
?>
