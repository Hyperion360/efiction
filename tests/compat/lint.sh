#!/bin/sh
set -eu
find . -type f -name '*.php' -not -path './tests/*' -print0 |
  xargs -0 -n 1 php -l >/dev/null
php -r 'define("_CHARSET", "UTF-8"); require "includes/corefunctions.php";'
