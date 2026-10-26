# Versioning and releases

_Split out of CLAUDE.md. Heading levels are as they were there._

## Versioning and releases

**Git tags are the source of truth; `CHANGELOG.md` is the human record.** No release tooling
and none wanted — `standard-version`, `changesets` and `semantic-release` are all npm.
Composer's `version` field is deliberately absent.

SemVer, and **pre-1.0 is meant literally**. While the major is 0, the line is the schema:

- **Minor** — the release changes the database and a deploy needs a migration.
- **Patch** — everything else, however large. If a deploy is `git pull` and nothing more, it
  is a patch.

Three places carry the number and are bumped together:

| Where | For |
| --- | --- |
| `git tag -a vX.Y.Z` | the source of truth |
| `CHANGELOG.md` heading | what changed and why |
| `config/version.php` | what a *running* container can report |

The last is the only copy that can drift — bump it in the changelog commit.

### Patch notes

**`PATCHNOTES.md` is the players' changelog**, written by hand in plain words;
`CHANGELOG.md` stays the technical record. One `## X.Y.Z (YYYY-MM-DD)` heading per
release (a trailing colon is allowed), read by `App\Support\PatchNotes`, rendered with
raw HTML stripped.

- **What is new is decided by the newest NOTE, never the app version** — a release
  nobody wrote a note for opens nothing.
- **Signed in only.** A signed-in player sees everything newer than
  `users.last_seen_changelog` in a modal that opens by itself; NULL sees the latest
  release alone. The version on the front page reopens every note.
- **Seen is written on CLOSE**, by a POST the dialog sends however it is closed
  (`data-show-on-load` / `data-post-on-close` in `ui-kit.js`), and it never moves
  backwards.

### Cutting a release

**Work on `main`; `bin/release [X.Y.Z]` does the branching, changelog, tag and merge, and
never pushes** (`--help` has the flags). Push with `git push origin main --follow-tags`.

- **When Claude is asked to cut one**: Claude commits the work, writes the CHANGELOG entry to a file and copies
  `bin/release X.Y.Z --changelog FILE` to the clipboard; the user runs it in their own terminal (nano, for the patch
  notes, cannot open inside Claude's shell) and **writes the patch notes themselves**.
  The changelog file must start with its own `## [X.Y.Z] — YYYY-MM-DD` heading (the script dies without it).
- **Patch notes are for players**: short and plain, no class names, migrations or reasoning.
- It refuses to rewind a `main` that has been pushed (`git fetch` first, or `--no-branch`),
  and a patch release that adds a migration must be confirmed.

Afterwards, run the migrations the changelog named and any seeder they need.
