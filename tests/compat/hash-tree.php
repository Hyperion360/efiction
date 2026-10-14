<?php
/**
 * Digest of a file tree: walks files recursively, sorts relative paths
 * bytewise, then feeds each relative path and file SHA-256 into one SHA-256
 * digest. Changing one byte in one file changes the digest.
 *
 * Usage: php tests/compat/hash-tree.php <dir>
 */
$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php tests/compat/hash-tree.php <dir>\n");
    exit(2);
}
$base = realpath($dir);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
$entries = array();
foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
    $entries[$rel] = hash_file('sha256', $file->getPathname());
}
ksort($entries, SORT_STRING);
$h = hash_init('sha256');
foreach ($entries as $rel => $sha) {
    hash_update($h, $rel . "\0" . $sha . "\0");
}
echo hash_final($h), "\n";
