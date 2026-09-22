#!/usr/bin/env node
// Read the edit changelog since plugin-lead's last review checkpoint.
//
//   node .claude/hooks/changelog-since.js               list changes since last checkpoint
//   node .claude/hooks/changelog-since.js --mark-reviewed   advance the checkpoint to now
//
// Used by the plugin-lead agent so it only re-reviews what changed since its
// own last CLEAR verdict, instead of the whole repo.

const fs = require('fs');
const path = require('path');

const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();
const logDir = path.join(projectDir, '.claude', 'logs');
const logFile = path.join(logDir, 'changelog.log');
const checkpointFile = path.join(logDir, 'review-checkpoint.json');

const mark = process.argv.includes('--mark-reviewed');

let checkpoint = '1970-01-01T00:00:00.000Z';
if (fs.existsSync(checkpointFile)) {
  try {
    const parsed = JSON.parse(fs.readFileSync(checkpointFile, 'utf8'));
    if (parsed.last_reviewed) checkpoint = parsed.last_reviewed;
  } catch (err) {
    // corrupt checkpoint — fall back to reviewing everything
  }
}

let lines = [];
if (fs.existsSync(logFile)) {
  lines = fs.readFileSync(logFile, 'utf8').split('\n').filter(Boolean);
}

const entries = lines
  .map((line) => {
    try { return JSON.parse(line); } catch (err) { return null; }
  })
  .filter((entry) => entry && entry.timestamp > checkpoint);

if (mark) {
  const latest = entries.reduce(
    (max, entry) => (entry.timestamp > max ? entry.timestamp : max),
    checkpoint
  );
  fs.mkdirSync(logDir, { recursive: true });
  fs.writeFileSync(
    checkpointFile,
    JSON.stringify({ last_reviewed: latest }, null, 2) + '\n'
  );
  console.log(`Checkpoint updated to ${latest}`);
} else if (!entries.length) {
  console.log('No new changes since last review.');
} else {
  for (const entry of entries) {
    console.log(`${entry.timestamp}  ${entry.tool}  ${entry.file}`);
  }
}
