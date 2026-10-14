# Maintenance changes

Changes made by the Hyperion360 maintenance fork on top of the upstream import (see docs/PROVENANCE.md). Each entry lists the confirmed defect, its exact reproduction, and the repair. This file starts as a blocker inventory and grows as repairs land.

## Baseline blockers (confirmed on import, 2026-10-14)

Confirmed means reproduced on PHP 8.3.35 and PHP 8.4.8 with `php -l`, or reproduced at runtime. Pattern matches alone were not counted.

| # | File:line | Defect | Reproduction | Status |
| --- | --- | --- | --- | --- |
| 1 | series.php:142 | Unparenthesized nested ternary. PHP 8 rejects it at compile time. | `php -l series.php` on 8.3/8.4: "Unparenthesized `a ? b : c ? d : e` is not supported." | open |
| 2 | admin/stories.php:158 | Genuine syntax error: extra closing paren after `format_story($storytext, $allowed_tags))`. The file never parsed on any PHP version, so the admin story preview at this code path was broken upstream. | `php -l admin/stories.php`: "Unclosed '{' on line 116 does not match ')' in ... on line 158". | open |
| 3 | includes/corefunctions.php:369 | Curly brace string offset `$words[$j]{0}` in `replace_naughty()`. Removed syntax; PHP 8.3 raises a compile-time fatal. | `php -l includes/corefunctions.php` on 8.3: "Array and string offset access syntax with curly braces is no longer supported." | open |
| 4 | includes/PHPMailerAutoload.php:45 | `__autoload()` declaration. Removed in PHP 8; fatal when the file loads (it is included by includes/emailer.php). | `php -l includes/PHPMailerAutoload.php` on 8.3: "__autoload() is no longer supported". | open |
| 5 | header.php:31 | `get_magic_quotes_gpc()` call, removed in PHP 8. Fatal on every page load where header.php runs. | `php -r 'require "header.php";'` after bootstrap: Call to undefined function get_magic_quotes_gpc(). | open |
| 6 | user/login.php:70,74 and includes/get_session_vars.php:30,47 | `md5($email + $passwordHash)` uses arithmetic addition on two strings. On PHP 8 this raises TypeError for non-numeric strings, so login and remember-cookie sessions break. | Login with a normal email address on PHP 8: TypeError "Unsupported operand types: string + string". | open |
| 7 | includes/mysqli_functions.php:12 | `SET SESSION sql_mode = 'MYSQL40'`. MySQL 8.0 removed the MYSQL40 mode; the statement errors, and under strict mysqli reporting the connection wrapper fails on every page. | Install or page load on MySQL 8.0 with MYSQLI_REPORT_ERROR: "Variable 'sql_mode' can't be set to the value of 'MYSQL40'". | open |
| 8 | includes/class.phpmailer.php:2583,2595 | `set_magic_quotes_runtime()` calls, removed in PHP 8, inside the mail send path. | Sending mail on PHP 8: Call to undefined function set_magic_quotes_runtime(). | open |
| 9 | includes/mysql_functions.php:14 | Same MYSQL40 statement in the old ext/mysql wrapper. The ext/mysql extension was removed in PHP 7, so this file is unreachable on PHP 8. Recorded for completeness; not repaired. | Static review; cannot execute on PHP 8. | wontfix (dead code) |

Security defects (viewstory.php access condition, viewpage.php eval, CSRF in admin/custpages.php and admin/messages.php) are tracked in the Task 5 section below once confirmed with tests.

## Repairs

(landed repairs are appended here with their commit references)

## New files added by this fork

- LICENSE (GPL-2.0 text; upstream ships no standalone license file)
- CREDITS.md, docs/PROVENANCE.md, this file
- tests/compat/ test harness files
- README.md, docs/COMPATIBILITY.md, .github/workflows/ (added by later tasks)
