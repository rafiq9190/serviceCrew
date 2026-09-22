
#!/usr/bin/env node
// PostToolUse hook: appends one JSONL entry per Edit/Write/MultiEdit/NotebookEdit
// call to .claude/logs/changelog.log, for plugin-lead to review "since last checkpoint".
// Never throws — a logging failure must not block the tool call it's attached to.

const fs = require('fs');
const path = require('path');

let raw = '';
process.stdin.on('data', (chunk) => { raw += chunk; });
process.stdin.on('end', () => {
  try {
    const input = JSON.parse(raw);
    const toolInput = input.tool_input || {};
    const filePath = toolInput.file_path || toolInput.notebook_path || null;

    if (filePath) {
      const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();
      const logDir = path.join(projectDir, '.claude', 'logs');
      fs.mkdirSync(logDir, { recursive: true });

      const relFile = path.relative(projectDir, filePath).split(path.sep).join('/');
      const entry = {
        timestamp: new Date().toISOString(),
        tool: input.tool_name || null,
        file: relFile,
        session_id: input.session_id || null,
      };

      fs.appendFileSync(path.join(logDir, 'changelog.log'), JSON.stringify(entry) + '\n');
    }
  } catch (err) {
    // swallow — logging is best-effort
  }
  process.exit(0);
});
