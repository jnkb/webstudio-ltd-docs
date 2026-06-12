---
description: "Use when editing api.php or auth.php, JSON persistence, auth/session handling, uploads, file safety, or protected data storage in Webstudio Docs."
name: "Backend JSON Auth Guidelines"
applyTo:
  - "api.php"
  - "auth.php"
---

# Backend JSON Auth Guidelines

- [../../api.php](../../api.php) and [../../auth.php](../../auth.php) are small request handlers, not framework controllers. Keep logic direct and procedural.
- Preserve shared session behavior across both files: same `SESSION_NAME`, same cookie settings, same-origin expectations, and compatible authenticated state.
- Mutating API actions should continue to require auth unless the task explicitly defines a public endpoint.
- Keep persisted data file-based and human-readable: JSON should remain UTF-8 and pretty-printed.
- Treat page IDs and filenames as hostile input. Keep or strengthen sanitization for page IDs, uploaded filenames, and delete targets.
- Changes that create or move persisted files must preserve the protection model for `data/`, including denial of direct web access.
- Upload changes must keep MIME validation, size limits, and web-relative URL behavior consistent with existing frontend expectations.
- If you touch password handling, preserve bcrypt-based storage, session regeneration on successful auth, and rate-limiting behavior unless the task explicitly changes those rules.
- When changing response shapes, check the corresponding fetch callers in [../../index.php](../../index.php) so the frontend and backend stay aligned.
- Prefer small helper reuse over new abstraction layers; this repository values simplicity and maintainability over architecture expansion.

## Quick validation

- Run `php -l api.php` and `php -l auth.php` after edits.
- If persistence changed, validate existing JSON files still decode cleanly.
- If auth changed, manually verify setup, login, logout, and one authenticated write action such as saving a page or settings.