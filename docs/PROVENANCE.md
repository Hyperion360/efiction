# Provenance

This document records where this fork's code came from and how the import was verified. Last updated: 2026-10-14.

## What this is

An unofficial maintenance fork of the eFiction 3.5.5 stable line, published by [Danilo Stern-Sapad](https://danilosapad.com/) on behalf of [Hyperion360](https://hyperion360.com/) Inc. It is not affiliated with the original eFiction maintainers. The repairs and maintenance are his work; he also developed the successor, Kiption (https://github.com/Hyperion360/kiption), from the ground up. The scope is compatibility repair for PHP 8.3/8.4 and current MySQL/MariaDB, plus fixes for the security defects listed in CHANGES-MAINTENANCE.md.

## Upstream identification

- The eFiction project on SourceForge (https://sourceforge.net/projects/efiction/) hosts three files: eFiction1.zip (1.0), eFiction1.1.zip (1.1d), and eFiction352.zip (3.5.2, released 2010-01-31). It does not host 3.5.5.
- The project's official GitHub organization (https://github.com/eFiction) hosts `v3_stable`, described as "Stable branch with current fixes". Its `version.php` declares `3.5.5`. The project stopped bumping `version.php`: the `v3.5.6` tag declares 3.5.5 as well. This branch is the continuation of the 3.5.5 line and is the newest official stable source.
- Upstream has no published 3.5.5 release ZIP and no standalone LICENSE file. The GPL notice ships inside `README.txt`.

## Import verification

The import base is commit `c8ea34c5059454f21fb0dce1bede22ede0f45a89` (master of eFiction/v3_stable, dated 2020-10-07 12:43:40 +0200), fetched from https://github.com/eFiction/v3_stable.git.

Verification method, performed 2026-10-14:

1. Exported the upstream tree with `git archive c8ea34c5059454f21fb0dce1bede22ede0f45a89`.
2. Export archive SHA-256: `b406c482e096222cb8acf0b68030e86e81bb667d5d30bc1548fe5bd36e4d4814`.
3. Compared that tree, file by file, against a pre-existing local archival copy of the distribution (1451 files, SHA-256 each): byte-identical on every file. The archival copy held no additional files.

Both sources agree, so the import can be reproduced from either the upstream git commit or the export archive hash above.

## Ancestry claims

- This repository starts a new git history at the import commit. It does not use the GitHub Fork button and does not claim commit-level ancestry with upstream.
- This fork does not claim to reproduce a historical 3.5.5 release ZIP byte for byte. It imports the official stable branch tree that self-identifies as 3.5.5, including the post-2016 PHP 7 compatibility fixes upstream applied on that branch.
- All upstream copyright notices, file headers, and the README.txt license notice are retained unmodified. This fork adds `LICENSE` (the full GPL-2.0 text) because upstream ships no standalone license file.

## Licensing verdict

The upstream tree is distributed under the GNU General Public License, version 2 or later:

- `README.txt`, which ships in the upstream tree, states: "This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License (GPL) as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version."
- Copyright (c) 2007 by Tammy Keefer, based on eFiction 1.1, Copyright (C) 2003 by Rebecca Smallwood.
- Third-party components carry their own licenses. See CREDITS.md for the inventory. Two bundled skins (Berrygood, E-zfiction) are CC BY-NC and keep their own license files and required credit links.

## Contact

[Danilo Stern-Sapad](https://danilosapad.com/), on behalf of [Hyperion360](https://hyperion360.com/) Inc. Maintenance fork questions: see README.md.
