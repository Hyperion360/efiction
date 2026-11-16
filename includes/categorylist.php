<?php
define("_BASEDIR", "../");
include("../config.php");
include("../includes/dbfunctions.php");
// Maintenance fork (3.5.88 port): the settings row is read with the sitekey
// escaped, the language constant is validated against the filesystem, and
// the settings the language file interpolates are supplied before it loads,
// so PHP 8 notices never leak into the JavaScript stream.
$settings = dbassoc(dbquery("SELECT * FROM ".$settingsprefix."fanfiction_settings WHERE sitekey = '".escapestring($sitekey)."'"));
$tableprefix = isset($settings['tableprefix']) ? $settings['tableprefix'] : '';
define("TABLEPREFIX", $tableprefix);
foreach(array('allowed_tags', 'recentdays', 'sitename', 'url', 'multiplecats', 'minwords', 'maxwords', 'pwdsetting', 'imagewidth', 'imageheight') as $name) {
	if(isset($settings[$name])) $$name = stripslashes((string)$settings[$name]);
}
$lang = isset($settings['language']) && is_string($settings['language']) && preg_match('/^[A-Za-z0-9_-]+$/D', $settings['language']) ? $settings['language'] : 'en';
if(!is_file(_BASEDIR."languages/$lang.php")) $lang = 'en';
include(_BASEDIR."languages/$lang.php");
require_once __DIR__.'/category_helpers.php';
header("Content-Type: text/javascript; charset="._CHARSET,true);
header('X-Content-Type-Options: nosniff');
include("../includes/queries.php");

$catid = isset($_GET['catid']) && is_string($_GET['catid']) && preg_match("/^[0-9]+$/D", $_GET['catid']) ? $_GET['catid'] : -1;
// Maintenance fork (3.5.85 port): pre-upgrade restores of very old lines
// have no leveldown/displayorder columns. Sort by what exists instead of
// fataling and leaving the dropdowns empty.
$ef_has = array();
$ef_cols = dbquery("SHOW COLUMNS FROM ".TABLEPREFIX."fanfiction_categories");
while($ef_c = dbassoc($ef_cols)) $ef_has[] = $ef_c['Field'];
$ef_order = (in_array('leveldown', $ef_has) && in_array('displayorder', $ef_has)) ? 'leveldown, displayorder' : 'catid';
$cats = dbquery("SELECT * FROM ".TABLEPREFIX."fanfiction_categories WHERE parentcatid = '$catid' OR catid = '$catid' ORDER BY ".$ef_order);
// Maintenance fork (3.5.84 port, widened by the 3.5.88 port): the element
// name is reflected into JavaScript, so it must be a validated identifier
// (or numeric select index) emitted through JSON encoding.
$element = isset($_GET['element']) && is_string($_GET['element']) && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_.-]*|[0-9]+)$/D', $_GET['element']) ? $_GET['element'] : '';
echo "var el = ".ef_category_json($element).";\n";
$x = 0;
while($category = dbassoc($cats)) {
	$label = ef_category_utf8(ef_category_name(stripslashes((string)$category['category'])));
	echo "categories[$x] = new category(".(int)$category['parentcatid'].", ".(int)$category['catid'].", ".ef_category_json(urlencode($label)).", ".(int)$category['locked'].", ".(isset($category['displayorder']) ? (int)$category['displayorder'] : 0).");\r\n";
	$x++;
}
?>
