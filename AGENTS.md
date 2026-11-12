# AGENTS.md — eFiction maintenance fork

Working rules for this repository. Read together with `~/Sites/KIP.md`
(ecosystem-wide rules; a local file, not part of this repo).

## Where private material goes

This is a public repository. Plans, research, and working notes are LOCAL-ONLY
and gitignored. Put them in these paths, never in a commit:

| Material | Path (gitignored) |
|---|---|
| Implementation plans, drafts, reviews, handoff write-ups | `docs/superpowers/` |
| Research | `research/` |
| QA reports | `qa-full-reports/`, `daily-qa-reports/` |
| Working notes | `TODOS.md`, `handoff-*.md` |
| Per-repo private memory | `AGENTS.local.md` |
| Tool state | `.gstack/` |

If a private file ends up tracked anyway, flag it to the owner: removing it
from public history is an owner decision (fresh-history rewrite), never a
quiet working-tree delete.

## Repo conventions

- Branches: `<type>/<topic>` (feat, fix, docs, chore, refactor); `main` is
  the only long-lived branch.
- Commits: repo git identity; no tool-credit trailers or tool names in
  messages.
- Pull-request bodies and comments, here and in every eFiction-lineage
  repo we contribute to, are polite, friendly, and competent: no talking
  down or correcting the other person; contractions (rewrite any
  sentence that can use don't/can't/it's and doesn't); no
  self-deprecation or hedging - state findings plainly, credit their
  commit as the lead, let the reproduction and the fix carry the
  authority. Concise, straightforward, plain English; get to the point
  and stay on it, no filler flourishes or metaphors ("rabbit hole").
  Active voice, natural tense: "the endpoint returns a fatal," not "a
  fatal was returned"; past for the investigation and history, present
  for what the code does now and what the PR changes, future only for
  reader steps - don't force present tense where it reads unnaturally.
  Technical precision (file names, reproductions, commands) is
  unchanged.
- Every push passes the local pre-push leak scan (public visibility) before
  it leaves the machine.
- GitHub is canonical. SourceForge (`sf` remote) is a mirror kept current by
  `.github/workflows/sourceforge-mirror.yml`; pushes to it use explicit refs
  (`main --tags`), never `--mirror`.
- SourceForge refuses non-fast-forward branch pushes. After any history
  rewrite: push a temp branch with the rewritten history, flip
  `/admin/code/set_default_branch_name` to it (the bare admin forms only
  accept posts that include the `_csrf_token` cookie value as a form field),
  delete and re-push `main`, then flip the default back and delete the temp
  branch.
