# PRD: FusionPBX Admin Password Recovery

## Objective

Restore reliable FusionPBX web admin access without changing FreeSWITCH runtime behavior or unrelated users.

## Requirements

- Confirm FusionPBX 5.5 password verification logic from local code.
- Inspect server `v_users` and group membership.
- Reset affected admin password using the same hash format FusionPBX writes from the UI.
- Verify login through the real domain URL.
- Record evidence and remaining risks in `.omx`.

## Acceptance Criteria

- `admin` can log in at `https://192.168.2.2/login.php`.
- Login redirects to `/core/dashboard/`.
- Password hashes are bcrypt-style `$2y$...`.
- `salt` is cleared for reset users.
- Evidence is persisted in `.omx/context`.
