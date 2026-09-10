# FusionPBX Admin Password Recovery

## Task

Recover FusionPBX web admin login after the original install-script credentials stopped working.

## Local Authentication Chain Findings

Relevant local files:

- `core/authentication/resources/classes/plugins/database.php`
- `core/users/user_edit.php`
- `resources/functions/password.php`

FusionPBX 5.5 authentication logic:

- If `v_users.password` starts with `$`, login uses PHP `password_verify()`.
- Otherwise it falls back to deprecated legacy check:
  - `md5($row["salt"] . $this->password) === $row["password"]`
- On successful legacy login, FusionPBX rehashes the password with `password_hash(..., PASSWORD_DEFAULT, ['cost' => 10])` and clears `salt`.

User edit logic writes:

- `password = password_hash($password, PASSWORD_DEFAULT, ['cost' => 10])`
- `salt = null`

## Server Findings

Server database had three enabled `admin` users, all in `superadmin`, all with legacy 32-character MD5-style password hashes and non-null salts:

```text
admin|3b2d16b5-d1b6-468e-8a4d-a7c2bcb3460e|superadmin
admin|3d9abb81-9f54-4baf-a592-7acc707daadf|superadmin
admin|e7d974d0-1a7d-4693-a192-0090f0326138|superadmin
```

There are also three enabled `v_domains` rows for `192.168.2.2`, which caused an initial domain-name subquery update to fail with:

```text
ERROR: more than one row returned by a subquery used as an expression
```

## Recovery Applied

Reset all `username='admin'` rows to the same FusionPBX 5.5-compatible bcrypt hash and cleared `salt`.

Temporary password:

```text
FpbxReset2026_K8vQm4zN
```

Verification after update:

```text
hash_verify_ok
UPDATE 3
admin|...|salt=|hash_prefix=$2y$|enabled=true
```

## Login Verification

Important: login must be tested with the real FusionPBX domain URL:

```text
https://192.168.2.2/login.php
```

Testing with `https://127.0.0.1/login.php` fails because FusionPBX cannot resolve the per-domain user context for `127.0.0.1`.

Successful HTTP verification:

```text
POST /login.php
HTTP/1.1 302 Found
Location: /core/dashboard/

final=https://192.168.2.2/core/dashboard/
code=200
title=Dashboard - FusionPBX
```

## Remaining Risks

- There are duplicate `v_domains` rows for `192.168.2.2`.
- There are duplicate `admin` users for the same apparent domain name.
- The immediate login problem is fixed by resetting all duplicate admin rows, but the duplicate domain/user state should be cleaned up later after confirming which domain UUID is authoritative.
- The temporary password should be changed from the FusionPBX UI immediately after login.
