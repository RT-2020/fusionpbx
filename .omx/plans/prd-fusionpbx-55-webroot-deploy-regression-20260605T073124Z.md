# PRD: Recover FusionPBX Webroot Deploy Regression

## Objective
Restore a stable FusionPBX + FreeSWITCH server after a local repository package was extracted over `/var/www/fusionpbx`, while determining whether the local code package is incompatible with the server's installed state.

## Scope
- Diagnose local repository risks related to FusionPBX version, FreeSWITCH config templates, and login bootstrap.
- Diagnose server state using SSH.
- Apply narrow server-side recovery changes only after backing up changed files.
- Avoid changing local application code unless evidence shows a local defect.

## Acceptance Criteria
- Identify the local repository version and compare it with server database/application state.
- Confirm whether web login fails due to PHP error, config file absence, database schema mismatch, session/cache permissions, or authentication data.
- Confirm whether FreeSWITCH instability is caused by loaded config/module choices.
- Leave FreeSWITCH stable for an observation window and `fs_cli -x status` usable, or document a hard blocker with exact evidence.
- Leave a concise recovery recommendation for future deployments from local packages.

## Non-goals
- Reinstall the entire server unless recovery is impossible.
- Replace the user's deployment strategy wholesale without evidence.
- Refactor local FusionPBX code.
