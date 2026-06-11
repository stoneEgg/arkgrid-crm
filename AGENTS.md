# ArkGrid Codex Operating Rules

1. Never modify the production directory directly.
2. Never edit outputs/private/config.php, .env, API keys, tokens, passwords, SSH keys, OAuth tokens, or secrets.
3. All database changes must be delivered as SQL migration files under database/migrations/.
4. Do not run destructive SQL against production.
5. Do not add credentials to Git.
6. Do not deploy production automatically.
7. Do not call external APIs unless explicitly approved.
8. Every task must stay on its own branch and worktree.
9. Every PHP change must pass:
   find outputs -name "*.php" -print0 | xargs -0 -n1 php -l
10. High-risk business actions require human approval.
11. Machine tokens cannot approve, reject, delete, change pricing, modify credentials, or perform payments.
12. For Pylon automation, do not bypass login, 2FA, CAPTCHA, SSO, or access controls.
13. When finished, provide:
   - changed files
   - migration files
   - test commands
   - rollback notes
