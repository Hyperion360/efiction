<?php
// Category-only storage/rendering policy, ported from official eFiction
// 3.5.87-3.5.88 (their includes/category_helpers.php; GPL v2, same
// lineage). The description engine is 3.5.86's review-safe sanitizer
// (writingqol.php) ported without its [spoiler] reader feature, which this
// fork does not carry. Do not change descript() globally: these rules
// apply to category fields only.
if(!defined('_CHARSET')) exit;
function ef_category_h($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, _CHARSET);
}
function ef_category_name($value) {
	return trim(strip_tags(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, _CHARSET)));
}
function ef_category_label($stored) {
	return ef_category_h(ef_category_name(stripslashes((string)$stored)));
}
function ef_category_description($value) {
	// The safe-HTML engine intersects allowed_tags with safe elements, runs
	// descript(), canonicalizes attributes, and rejects executable URLs.
	return ef_review_safe_html((string)$value);
}
function ef_category_image($value) {
	// This field is a filename below the skin's images directory, not HTML/URL.
	$value = trim((string)$value);
	return preg_match('~^[^\x00-\x1f\x7f<>"\x27\\\\:?#]+$~D', $value)
		&& !preg_match('~(^|/)\.\.(/|$)|^/~', $value) ? $value : '';
}
function ef_category_utf8($value) {
	$value = (string)$value;
	if(strcasecmp(_CHARSET, 'UTF-8') === 0) return $value;
	return function_exists('mb_convert_encoding') ? mb_convert_encoding($value, 'UTF-8', _CHARSET) : $value;
}
function ef_category_json($value) {
	return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
}

// The 3.5.86 review-safe HTML engine, ported for ef_category_description().
function ef_review_url_safe($value, $image = false) {
	$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, _CHARSET);
	if(preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return false;
	if($image && preg_match('~^data:image/(png|gif|jpe?g|webp);base64,[a-z0-9+/=]+$~i', $value)) return true;
	return !preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)
		|| (bool)preg_match($image ? '~^https?://~i' : '~^(https?://|mailto:|tel:|ftp://)~i', $value);
}
function ef_review_tag($tag, $attributes) {
	// Use the established sanitizer first, then canonicalize attributes so a
	// browser cannot reinterpret malformed syntax or encoded protocol values.
	$clean = descript('<'.$tag.$attributes.'>');
	if(!preg_match('~^<'.preg_quote($tag, '~').'\b(.*)>$~is', $clean, $m)) return '<'.$tag.'>';
	preg_match_all('~([a-z][a-z0-9_:.-]*)(?:\s*=\s*("[^"]*"|\x27[^\x27]*\x27|[^\s>]+))?~i', $m[1], $attrs, PREG_SET_ORDER);
	$out = '<'.$tag; $seen = array();
	foreach($attrs as $a) {
		$name = strtolower($a[1]);
		if(isset($seen[$name]) || strpos($name, 'on') === 0 || strpos($name, ':') !== false
			|| in_array($name, array('srcdoc', 'formaction', 'xmlns', 'srcset', 'background', 'ping'), true)) continue;
		$seen[$name] = true;
		if(!isset($a[2])) continue;
		$value = $a[2];
		if($value[0] === '"' || $value[0] === "'") $value = substr($value, 1, -1);
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, _CHARSET);
		if(in_array($name, array('href', 'src', 'cite', 'longdesc'), true) && !ef_review_url_safe($value, $name === 'src' && $tag === 'img')) continue;
		if($name === 'style') {
			// Retain ordinary legacy CSS, not escaped/obfuscated executable CSS.
			if(preg_match('~[\\\\@<>\x00-\x1f]|/\*|expression\s*\(|behaviou?r\s*:|-moz-binding|(?:java|vb|live)script\s*:~i', $value)) continue;
			$unsafe = false;
			preg_replace_callback('~url\s*\(\s*([\x22\x27]?)(.*?)\1\s*\)~i', function($u) use (&$unsafe) {
				if(!ef_review_url_safe(trim($u[2]), true)) $unsafe = true;
				return '';
			}, $value);
			if($unsafe || preg_match('~url\s*\(~i', preg_replace('~url\s*\([^)]*\)~i', '', $value))) continue;
		}
		$out .= ' '.$name.'="'.ef_category_h($value).'"';
	}
	return $out.'>';
}
function ef_review_safe_html($html) {
	global $allowed_tags;
	preg_match_all('~<\s*([a-z][a-z0-9]*)\b~i', (string)$allowed_tags, $configured);
	$allowed = array_intersect(array_map('strtolower', $configured[1]), explode(' ',
		'a abbr acronym b bdi bdo big blockquote br caption center cite code col colgroup dd del div dl dt em font h1 h2 h3 h4 h5 h6 hr i img ins kbd li mark ol p pre q rp rt ruby s samp small span strike strong sub sup table tbody td tfoot th thead tr tt u ul var wbr'));
	$parts = preg_split('~(<(?:[^>\x22\x27]|\x22[^\x22]*\x22|\x27[^\x27]*\x27)*>)~s', (string)$html, -1, PREG_SPLIT_DELIM_CAPTURE);
	$out = ''; $stack = array(); $open = array();
	$close = function() use (&$stack) { $s = ''; foreach(array_reverse($stack) as $t) $s .= '</'.$t[0].'>'; return $s; };
	foreach($parts as $part) {
		if($part === '') continue;
		if($part[0] === '<' && preg_match('~^<\s*(/?)\s*([a-z][a-z0-9]*)\b(.*)>$~is', $part, $m)) {
			$tag = strtolower($m[2]);
			if(!in_array($tag, $allowed, true)) continue;
			if($m[1]) {
				// A counting map keeps close tags O(1); in_array over a
				// materialized column of the stack made deep nesting quadratic
				// (pentest T6 measurement: 29 s at 448 KB of nested tags).
				if(!empty($open[$tag])) {
					do { $end = array_pop($stack); $open[$end[0]]--; $out .= '</'.$end[0].'>'; } while($end[0] !== $tag);
				}
			} else {
				$start = ef_review_tag($tag, $m[3]); $out .= $start;
				if(!in_array($tag, array('br','hr','img','col','wbr'), true)) {
					$stack[] = array($tag, $start);
					$open[$tag] = isset($open[$tag]) ? $open[$tag] + 1 : 1;
				}
			}
			continue;
		}
		if(preg_match('~^<\s*(?:!|\?)~', $part)) continue; // comments/declarations
		$out .= ef_category_h(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, _CHARSET));
	}
	return $out.$close();
}
