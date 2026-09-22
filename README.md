<img width="1200" height="720" alt="banner" src="https://github.com/user-attachments/assets/7261f143-92b6-416c-ace9-e3d3babc94d0" />

# WordPress AI Agent Skills

A collection of [Agent Skills](https://agentskills.io) for professional WordPress development. These skills ensure your AI coding agent — whether it's Claude, Cursor, Windsurf, Antigravity, GitHub Copilot, Codex, or any other — follows official WordPress coding standards, security best practices, and WordPress.org directory guidelines when building plugins, or reviewing existing plugins for security vulnerabilities and repository readiness.

## 🎯 What Are Agent Skills?

[Agent Skills](https://agentskills.io) are an **open standard** for extending AI agents with specialized capabilities. A skill is simply a folder containing a `SKILL.md` file (with YAML frontmatter + instructions) and optional supporting files like `references/`, `scripts/`, and `assets/`.

All major AI coding tools now support the same skills format natively:

|Tool              |Project Skills Location|Global Skills Location         |Docs                                                                         |
|------------------|-----------------------|-------------------------------|-----------------------------------------------------------------------------|
|**Claude Code**   |`.claude/skills/`      |—                              |[docs](https://docs.anthropic.com/en/docs/claude-code/skills)                |
|**Cursor**        |`.cursor/skills/`      |`~/.cursor/skills/`            |[docs](https://cursor.com/docs/context/skills)                               |
|**Windsurf**      |`.windsurf/skills/`    |`~/.codeium/windsurf/skills/`  |[docs](https://docs.windsurf.com/windsurf/cascade/skills)                    |
|**Antigravity**   |`.agent/skills/`       |`~/.gemini/antigravity/skills/`|[docs](https://antigravity.google/docs/skills)                               |
|**GitHub Copilot**|`.github/skills/`      |`~/.copilot/skills/`           |[docs](https://code.visualstudio.com/docs/copilot/customization/agent-skills)|
|**Codex (OpenAI)**|`.codex/skills/`       |`~/.codex/skills/`             |[docs](https://developers.openai.com/codex/skills)                           |

**Write once, use everywhere.** Skills you create for one tool work across all tools that support the standard.

## 📦 Included Skills

### 1. `wp-plugin-dev` — WordPress Plugin Development

Guides your AI agent to build production-ready WordPress plugins with proper architecture and security.

**Triggers when you say things like:**

- “Build me a plugin for X”
- “Create a WordPress plugin”
- “I need a WooCommerce extension”
- “Add a REST API endpoint”

**What it enforces:**

- **Clean bootstrap** — Main plugin file contains only constants, hooks, and autoloading. No feature logic.
- **Modular architecture** — Every feature gets its own class file (`includes/`, `admin/`, `public/`, `api/`)
- **Security** — Mandatory `sanitize_*()` on all input, `esc_*()` on all output, `$wpdb->prepare()` for all SQL, nonces on all forms, capability checks on all privileged actions
- **Caching** — Transients API and `wp_cache_*` for expensive operations
- **WordPress.org compliance** — GPL licensing, `readme.txt`, `uninstall.php`, proper prefixing, translatable strings

**Supported plugin types:**

|Type                                 |Boilerplate Included|
|-------------------------------------|--------------------|
|Standard (settings, CPTs, shortcodes)|✅                   |
|WooCommerce extensions               |✅                   |
|Gutenberg block plugins              |✅                   |
|REST API / headless plugins          |✅                   |
|AJAX handlers                        |✅                   |
|Custom database tables               |✅                   |

<details>
<summary>File structure</summary>

```
wp-plugin-dev/
├── SKILL.md                        # Main instructions (177 lines)
└── references/
    ├── architecture.md             # Boilerplate for all plugin types (742 lines)
    ├── security.md                 # Sanitization, escaping, $wpdb, nonces, caching (216 lines)
    └── wp-org-guidelines.md        # Condensed 18 official guidelines (31 lines)
```

</details>

-----

### 2. `wp-plugin-review` — WordPress Plugin Review

Guides your AI agent to perform a comprehensive code review of any WordPress plugin, combining automated tooling with deep manual analysis.

**Triggers when you say things like:**

- "Review this plugin"
- "Audit a WordPress plugin"
- "Check plugin security"
- "Check my plugin for repository submission"
- "Plugin code review"

**What it does:**

- **Two-phase review** — Runs automated tools (PHPCS with WPCS, PHPStan, PHPUnit) followed by deep manual code inspection
- **Security audit** — Checks input sanitization, output escaping, SQL injection, nonce verification, capability checks, file security, and external request handling
- **Coding standards** — Validates naming conventions, hook usage, enqueuing, internationalization, and PHP compatibility
- **Repository readiness** — Verifies readme.txt, plugin headers, licensing, prefixing, data/privacy compliance, and admin UX
- **Unit test assessment** — Evaluates test existence, quality, coverage areas, and CI/CD readiness
- **Accessibility review** — Checks ARIA attributes, keyboard navigation, color contrast, screen reader support, and semantic HTML

**Report output:**

| Severity | Meaning |
|----------|---------|
| CRITICAL | Security vulnerability or blocking issue — must fix |
| HIGH | Significant issue — may cause repository rejection |
| MEDIUM | Best practice violation — recommended fix |
| LOW | Minor improvement — nice to have |
| PASS | Area passes review |

The final report includes an overall score out of 100, per-category scores, before/after code fix snippets, and a priority-ordered list of recommended fixes.

<details>
<summary>File structure</summary>

```
wp-plugin-review/
├── SKILL.md                            # Main instructions (162 lines)
├── scripts/
│   └── setup_tools.sh                  # Auto-installs PHPCS, PHPStan, PHPUnit (103 lines)
└── references/
    ├── security-checklist.md           # Full security review checklist (270 lines)
    ├── repo-guidelines-checklist.md    # WordPress.org repository guidelines (234 lines)
    └── report-template.md             # Markdown report template (215 lines)
```

</details>

-----

## 🚀 Installation

Since all major AI coding tools now support the **[Agent Skills open standard](https://agentskills.io)**, the setup is almost identical across platforms — just copy the skill folders into your tool’s skills directory.

### Quick Install (All Platforms)

```bash
# Clone the repo
git clone https://github.com/wpacademy/wordpress-dev-skills
```

Then copy the skill folders to your platform’s skills directory:

|Platform          |Command                                                                  |
|------------------|-------------------------------------------------------------------------|
|**Claude Code**   |`cp -r wordpress-dev-skills/wp-plugin-dev your-project/.claude/skills/`  |
|**Cursor**        |`cp -r wordpress-dev-skills/wp-plugin-dev your-project/.cursor/skills/`  |
|**Windsurf**      |`cp -r wordpress-dev-skills/wp-plugin-dev your-project/.windsurf/skills/`|
|**Antigravity**   |`cp -r wordpress-dev-skills/wp-plugin-dev your-project/.agent/skills/`   |
|**GitHub Copilot**|`cp -r wordpress-dev-skills/wp-plugin-dev your-project/.github/skills/`  |
|**Codex**         |`cp -r wordpress-dev-skills/wp-plugin-dev your-project/.codex/skills/`   |


> Repeat the same for `wp-plugin-review`.

-----

### Claude Code

```bash
cp -r wordpress-dev-skills/wp-plugin-dev your-project/.claude/skills/
cp -r wordpress-dev-skills/wp-plugin-review your-project/.claude/skills/
```

Your project structure:

```
your-project/
└── .claude/
    └── skills/
        ├── wp-plugin-dev/
        │   ├── SKILL.md
        │   └── references/
        └── wp-plugin-review/
            ├── SKILL.md
            ├── scripts/
            └── references/
```

Skills are auto-discovered by Claude based on the `description` in each SKILL.md's frontmatter.

-----

### Cursor

Cursor supports Agent Skills natively since v2.4. Place skills in `.cursor/skills/`:

```bash
cp -r wordpress-dev-skills/wp-plugin-dev your-project/.cursor/skills/
cp -r wordpress-dev-skills/wp-plugin-review your-project/.cursor/skills/
```

Your project structure:

```
your-project/
└── .cursor/
    └── skills/
        ├── wp-plugin-dev/
        │   ├── SKILL.md
        │   └── references/
        └── wp-plugin-review/
            ├── SKILL.md
            ├── scripts/
            └── references/
```

Skills are auto-invoked when the agent determines they're relevant, or you can manually invoke with `@wp-plugin-dev` or `@wp-plugin-review` in the chat.

For global availability across all projects, copy to `~/.cursor/skills/` instead.

-----

### Windsurf (Cascade)

Windsurf supports Agent Skills natively. Place skills in `.windsurf/skills/`:

```bash
cp -r wordpress-dev-skills/wp-plugin-dev your-project/.windsurf/skills/
cp -r wordpress-dev-skills/wp-plugin-review your-project/.windsurf/skills/
```

Your project structure:

```
your-project/
└── .windsurf/
    └── skills/
        ├── wp-plugin-dev/
        │   ├── SKILL.md
        │   └── references/
        └── wp-plugin-review/
            ├── SKILL.md
            ├── scripts/
            └── references/
```

You can also create skills via the Cascade UI: **three dots menu → Skills → + Workspace**.

Skills are auto-invoked via progressive disclosure, or manually with `@wp-plugin-dev`.

For global availability, copy to `~/.codeium/windsurf/skills/` instead.

-----

### Google Antigravity

Antigravity supports Agent Skills natively. Place skills in `.agent/skills/`:

```bash
cp -r wordpress-dev-skills/wp-plugin-dev your-project/.agent/skills/
cp -r wordpress-dev-skills/wp-plugin-review your-project/.agent/skills/
```

Your project structure:

```
your-project/
└── .agent/
    └── skills/
        ├── wp-plugin-dev/
        │   ├── SKILL.md
        │   └── references/
        └── wp-plugin-review/
            ├── SKILL.md
            ├── scripts/
            └── references/
```

The agent loads only the skill metadata at session start and activates full instructions on-demand when relevant.

For global availability, copy to `~/.gemini/antigravity/skills/` instead.

-----

### GitHub Copilot

GitHub Copilot supports Agent Skills in VS Code, CLI, and the coding agent:

```bash
cp -r wordpress-dev-skills/wp-plugin-dev your-project/.github/skills/
cp -r wordpress-dev-skills/wp-plugin-review your-project/.github/skills/
```

Your project structure:

```
your-project/
└── .github/
    └── skills/
        ├── wp-plugin-dev/
        │   ├── SKILL.md
        │   └── references/
        └── wp-plugin-review/
            ├── SKILL.md
            ├── scripts/
            └── references/
```

For personal skills across all projects, copy to `~/.copilot/skills/` instead.

-----

### Any Other Tool

These skills follow the [Agent Skills open standard](https://agentskills.io/specification). For any tool that supports it, just copy the skill folders to the tool’s skills directory. For tools that don’t yet support the standard natively:

1. **Paste the SKILL.md content** as custom instructions or system prompt
1. **Attach reference files** as additional context when needed
1. Or **combine everything** into a single document for your tool’s rules/settings

-----

### Platform Comparison

|Feature               |Claude              |Cursor              |Windsurf                     |Antigravity                    |Copilot             |Codex               |
|----------------------|--------------------|--------------------|-----------------------------|-------------------------------|--------------------|--------------------|
|Skills location       |`.claude/skills/`   |`.cursor/skills/`   |`.windsurf/skills/`          |`.agent/skills/`               |`.github/skills/`   |`.codex/skills/`    |
|Format                |`SKILL.md` + folders|`SKILL.md` + folders|`SKILL.md` + folders         |`SKILL.md` + folders           |`SKILL.md` + folders|`SKILL.md` + folders|
|Auto-discovery        |✅                   |✅                   |✅                            |✅                              |✅                   |✅                   |
|Manual invoke         |—                   |`@skill-name`       |`@skill-name`                |prompt-based                   |`/skills`           |`$skill-name`       |
|Progressive disclosure|✅                   |✅                   |✅                            |✅                              |✅                   |✅                   |
|Global skills         |—                   |`~/.cursor/skills/` |`~/.codeium/windsurf/skills/`|`~/.gemini/antigravity/skills/`|`~/.copilot/skills/`|`~/.codex/skills/`  |


> **💡 Tip:** Since all platforms now follow the same Agent Skills standard, the skill folders are 100% portable. No conversion needed — just copy and use.

## 💡 Usage Examples

### Plugin Development

```
Build me a WordPress plugin called "Smart Reviews" that adds a custom
post type for reviews with star ratings, a REST API endpoint to fetch
reviews, and a Gutenberg block to display them.
```

Your AI agent will automatically:

- Scaffold a clean, modular plugin structure
- Keep the main file as a bootstrap loader only
- Create separate class files for the CPT, REST API, and block
- Sanitize all input, escape all output, use `$wpdb->prepare()`
- Generate `readme.txt` and `uninstall.php`

### Plugin Review

```
Review my "Smart Reviews" plugin for security issues, coding standards
compliance, and WordPress.org repository readiness. Generate a full report.
```

Your AI agent will automatically:

- Install and run PHPCS (with WordPress Coding Standards), PHPStan, and PHPUnit
- Perform a deep manual review of security patterns, coding standards, and repository guidelines
- Check accessibility of any admin UI components
- Evaluate unit test coverage and quality
- Generate a detailed Markdown report with severity ratings, before/after code fixes, and an overall score out of 100

## 📚 Sources & References

These skills are built from official WordPress documentation:

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [WordPress Security APIs](https://developer.wordpress.org/apis/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHPCS WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
- [WCAG 2.1 Level AA](https://www.w3.org/WAI/WCAG21/quickref/?levels=aaa)
- [Agent Skills Specification](https://agentskills.io/specification)

## 🤝 Contributing

Contributions are welcome! If you’d like to improve these skills:

1. Fork the [repository](https://github.com/wpacademy/wordpress-dev-skills)
1. Create a feature branch (`git checkout -b improve-security-reference`)
1. Make your changes
1. Test with your preferred AI agent to verify the skill produces correct output
1. Submit a pull request

**Areas that could use contributions:**

- Additional boilerplate patterns (e.g., WP-CLI commands, multisite support)
- Expanded Gutenberg block development patterns
- Performance optimization reference
- Testing/CI pipeline patterns

## 📄 License

This project is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html), consistent with WordPress itself.

## 👤 Author

**Mian** — Full-stack WordPress developer with 15+ years of experience.

- YouTube: [WP Academy](https://youtube.com/@wpacademy) (125K+ subscribers)
- Website: [msrbuilds.com](https://msrbuilds.com)
