# Test Spec: FusionPBX Admin Password Recovery

## Checks

- Local code inspection:
  - `database.php` uses `password_verify()` for `$...` hashes.
  - `user_edit.php` writes `password_hash()` and clears `salt`.
- Database inspection:
  - Admin users are enabled.
  - Admin users retain `superadmin` group.
- Hash verification:
  - PHP `password_verify(new_password, generated_hash)` returns true.
- Database update:
  - `UPDATE 3` admin rows.
  - Updated rows have `hash_prefix=$2y$`.
  - Updated rows have empty `salt`.
- HTTP verification:
  - `POST https://192.168.2.2/login.php` returns `302`.
  - Redirect location is `/core/dashboard/`.
  - Followed request returns `200`.
  - Returned page title contains `Dashboard - FusionPBX`.
