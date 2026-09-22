# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

A distribution of [Agent Skills](https://agentskills.io) for WordPress development. There is no application code, no build system, no test suite, and no dependencies — the deliverable is Markdown prompt content that gets copied into an agent's skills directory (`.claude/skills/`, `.cursor/skills/`, `.windsurf/skills/`, `.agent/skills/`, `.github/skills/`, `.codex/skills/`).

"Testing" a change means installing the skill into an agent and checking that it triggers on the intended prompts and produces correct output. Nothing here is executable except `wp-plugin-review/scripts/setup_tools.sh`, which runs inside the *reviewing agent's* sandbox, not here.

## Skill anatomy and the progressive-disclosure contract

Each top-level directory is one skill and must stay self-contained and portable — no tool-specific syntax, since the same folder ships to six different agents.

```
<skill-name>/
├── SKILL.md          # YAML frontmatter (name, description) + instructions
├── references/       # Loaded on demand, only when SKILL.md tells the agent to read them
└── scripts/          # Optional executables the agent runs
```

This split is the core design constraint:

- **`description:` in the frontmatter is the only text an agent sees at session start.** It is the trigger surface, so it enumerates the phrasings that should activate the skill ("build me a plugin for X", "review this plugin", uploaded plugin zip). Changing it changes when the skill fires.
- **`SKILL.md` body is loaded when the skill activates** — keep it to workflow, principles, and decision tables. It stays short (~160–190 lines).
- **`references/*.md` are loaded only if SKILL.md explicitly instructs the agent to read them.** Long boilerplate, full checklists, and templates live here. A reference file that no SKILL.md names is dead weight the agent will never open.

When adding detail, ask which layer it belongs to. Detail added to SKILL.md costs context on every activation; detail added to `references/` costs nothing until needed.

## Sandbox paths baked into the skills

Both skills assume the agent runs in a Linux sandbox with these conventions, and the paths appear in several files at once — change them together:

- `/mnt/user-data/uploads/` — where a user-supplied plugin zip/folder is found (`wp-plugin-review` Phase 1)
- `/mnt/user-data/outputs/` — where generated plugins and review reports are written
- `/home/claude/plugin-under-review/` — extraction target for the plugin being reviewed
- `/home/claude/.wp-review-tools/` and `/home/claude/.local/bin` — where `setup_tools.sh` installs PHPCS/WPCS, PHPStan, PHPUnit and symlinks them

`setup_tools.sh` additionally assumes Debian/Ubuntu (`apt-get`) and passwordless `sudo`. It is idempotent by design: every install step is guarded by a `[ ! -f "$TOOLS_DIR/vendor/bin/<tool>" ]` check, so re-running is cheap. Preserve that when editing it.

## Keeping README.md in sync

`README.md` is the project's front page and duplicates structural facts about each skill: per-skill file trees **with line counts** (e.g. "architecture.md — Boilerplate for all plugin types (742 lines)"), trigger phrase lists, feature tables, and per-platform install commands. Any change to a skill's file set, trigger phrases, or file length makes those blocks stale. Update README.md in the same commit.

Adding a whole new skill means touching README.md in several places: the "Included Skills" section, every platform's install command block (six of them), and the project-structure trees.

## The two skills

**`wp-plugin-dev`** — scaffolds plugins. Its non-negotiables, enforced through the SKILL.md checklist: the main plugin file is bootstrap only (constants, activation/deactivation hooks, autoloader, one init call — never feature logic); every feature is its own class under `includes/`, `admin/`, `public/`, `blocks/`, or `api/`; sanitize on input, escape on output, `$wpdb->prepare()` on all SQL, nonces on all forms, capability checks on all privileged actions. `references/architecture.md` holds the boilerplate for each plugin type; the SKILL.md "Plugin Type Reference" table is the index into it.

**`wp-plugin-review`** — audits an existing plugin in two phases: automated (PHPCS with WordPress/WordPress-Extra standards, PHPStan level 5, PHPUnit) then manual review across five categories (security, coding standards, repository guidelines, unit tests, accessibility). Severity scale is CRITICAL / HIGH / MEDIUM / LOW / PASS with an overall score out of 100. The output shape is fixed by `references/report-template.md` — edit that file rather than describing report structure in SKILL.md.

The two skills are complements: `wp-plugin-dev` writes against the same WordPress.org guidelines that `wp-plugin-review` audits against. A rule that changes in `wp-plugin-dev/references/wp-org-guidelines.md` or `security.md` usually has a matching entry in `wp-plugin-review/references/repo-guidelines-checklist.md` or `security-checklist.md`. Check both.

## Scope: plugin development only

This repo currently ships two skills, both plugin-focused: `wp-plugin-dev` and `wp-plugin-review`. A third skill, `wp-theme-dev` (block/classic/child theme development), previously existed and is still reachable in git history, but its files have been removed from the working tree and README.md no longer documents it — theme development is out of scope for now. Don't resurrect `wp-theme-dev` files or re-add theme references to README.md without being asked.

## Building the ServiceCrew plugin (agent workflow)

This directory doubles as the working directory for an actual plugin build, tracked separately from the skills-distribution content described above. `ServiceCrew-Plan-v2.md` is the spec. `ServiceCrew-Tasks.md` is the live, per-task status of everything in it — check that file, not conversation history, for what's actually built; it survives context compaction and new sessions, chat memory doesn't. As of this writing no Phase 1a code has landed (see `ServiceCrew-Tasks.md`); once it does, revisit the "What this repository is" / "Scope" sections above, which will be stale.

The build uses five project-level agents under `.claude/agents/`: `plugin-backend` and `plugin-frontend` implement one task at a time (never more); `plugin-lead` reviews each completed task against the plan and conventions before `plugin-qa` writes Playwright coverage for it; `plugin-reviewer` runs the full `wp-plugin-review` audit at each phase boundary, on top of `plugin-lead`'s ongoing per-task checks. `plugin-lead` and `plugin-reviewer` check against `.claude/references/plugin-conventions.md` — a checklist condensed once from the `wp-plugin-dev`/`wp-plugin-review` skill content — instead of re-reading those full skill folders on every pass; `plugin-reviewer` still invokes the full skill for its phase-end audit. A `PostToolUse` hook (`.claude/hooks/log-edit.js`, wired in `.claude/settings.json`) logs every edit to `.claude/logs/changelog.log` (gitignored); `plugin-lead` reads only what changed since its own last checkpoint via `.claude/hooks/changelog-since.js`, not the whole repo.

## License

GPLv2 or later, matching WordPress. Generated plugins and themes must carry a GPL-compatible license header.
