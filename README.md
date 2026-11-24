# CCDI Student Evaluation Portal

PHP 8.1+ · MySQL/MariaDB · vanilla JS (no build step)

## Install
1. Create an empty MySQL database (e.g. `ccdi_eval`) and a user for it.
2. Edit the `DB_*` values at the top of `config.php` (or set CCDI_DB_HOST / _NAME / _USER / _PASS).
3. Upload this folder to your PHP host (or run `php -S localhost:8000` inside it).
4. Open `/setup.php` once. It creates the tables and lets you choose the admin email + password.
5. **Delete `setup.php`** afterwards. Open `/index.html`.

## Flow
- Students: Sign Up (name, email, SID like `C-2024-00123`, password) -> Sign In -> pick Course + Block -> evaluate each professor.
  When the last evaluation is submitted, the confirmation email is simulated (logged in `email_log` and shown on screen).
- Admin: "Administrator sign-in" link on the login card. Manage professors, assign them to course/block/subject,
  set the active term, and read evaluations by course then block.

## Notes
- Passwords are stored with `password_hash()` and checked with `password_verify()`; no credentials are shown on any page.
- SID format is controlled by `SID_PATTERN` in `config.php`.
- To send real email, replace the `email_log` insert in `api.php` (`submit_evaluation`) with `mail()` or PHPMailer.
- Serve over HTTPS in production.

## Demo sign-in behavior
- Student: any nonempty student ID/email and any nonempty password. A student record is created automatically on first sign-in.
- Administrator: username `admin` and password `admin123` only. An administrator database record is created automatically if none exists.
- This is **demo-only authentication**, not safe for public deployment. Anyone can access student records by knowing their identifier, and the administrator password is public. Replace it with secure authentication before production use.
- Login card opacity is 78% so the CCDI logo watermark remains visible.
