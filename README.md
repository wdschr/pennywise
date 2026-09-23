# Pennywise

A private, self-hosted budget planner: income and expense tracking with
categories, recurring subscriptions, savings pots (including goal
tracking), reports with charts, PDF/XLSX export, and bulk import of
historical data. Built in plain PHP + MySQL so it runs on ordinary shared
hosting (no Node build step, no SSH/Composer needed on the server).

## Features

- Email/password login, session-based auth, remember-me, account lockout
  after repeated failed logins, forced password change for admin-created
  accounts.
- Every user has a fully private budget. Admins get read-only oversight of
  all budgets. A user can grant another user read-only access to specific
  categories only (e.g. share "Household Bills" but not "Luxury").
- Income and expense tracking, grouped into categories (Essential,
  Subscriptions, Travel, Borrowing, Unplanned, Luxury by default - fully
  editable, and you can add your own).
- Preset "quick add" items per category (e.g. TfL Travel, Bus Fares, Train
  Tickets). Mark a preset item as "carry forward" and it's automatically
  re-added each period at the same amount - monthly (on the 1st, the last
  Friday of the month, or a specific day) for expenses and income alike,
  or every 4 weeks from a chosen date for things like a benefit payment
  that isn't tied to the calendar month.
- Savings pots: simple running balance, or goal pots with a target amount
  and date and a progress bar. Archived pots tuck away into a collapsed
  section instead of cluttering the page.
- Reports with charts (income vs expense trend, category breakdown),
  exportable as PDF or XLSX.
- Bulk import from a downloadable CSV/XLSX template (pre-filled with your
  own category and pot names), with a validation preview before anything
  is saved, and one-click undo per import batch.
- Dark mode by default, with a light mode toggle that's remembered per
  account.
- A web installer (`/install/`) that creates the database schema, writes
  the configuration file, and creates your admin account - no manual SQL
  or file editing required.

## How it's built

- Plain PHP 8.1+, PDO/MySQL, no framework. All third-party libraries
  (TCPDF for PDF export, PhpSpreadsheet for XLSX import/export, PHPMailer
  for password-reset emails) are vendored into `private_html/vendor/` and
  committed to this repo, so deployment is a plain file upload - the
  server never needs Composer or SSH access.
- `public_html/` is the web root. `private_html/` sits *outside* the web
  root and holds `config.php` (database credentials and secrets, created
  by the installer), the PHP application classes, and vendored
  dependencies, so none of it is ever directly reachable over HTTP.
- No JS build step: vanilla JS (`public_html/assets/js/`) plus a locally
  vendored copy of Chart.js. A strict Content-Security-Policy
  (`script-src 'self'`, no inline scripts) is set on every response.

## Deploying to shared hosting

This is written for a typical cPanel-style shared hosting account, where
your account root has a `public_html/` folder as the web root. Adjust the
paths below if your host's layout differs.

1. **Create the database.** In your hosting control panel, find the MySQL
   database section, create a new database and a database user with full
   privileges on it. Note the database name, username, password and host
   (usually `localhost` or `127.0.0.1`).
2. **Choose your PHP version.** In your hosting control panel's PHP
   settings, set the site to PHP 8.1 or newer.
3. **Upload the files via FTP/SFTP:**
   - This repo's `public_html/` contents go into your hosting account's
     `public_html/` (or the web root of the domain/subdomain you're
     using).
   - This repo's `private_html/` contents go into a `private_html/`
     folder **at the same level as `public_html/`** - i.e. *outside* the
     web root, not inside it. Create this folder via FTP/file manager if
     it doesn't exist yet.
   - Make sure `private_html/` is writable by the PHP process (this is
     usually the default for your own account's files - no special
     chmod should be needed).
4. **Run the installer.** Visit `https://yourdomain/install/` in a
   browser. It checks PHP requirements, asks for your database details
   and site settings, and creates your admin account. You can leave the
   "Site URL" field blank if you haven't pointed a hostname at the
   hosting yet - the app auto-detects it from each request, and you can
   fill it in later by editing `private_html/config.php`.
5. **Lock down the installer.** Once installed, a lock file stops the
   wizard being run again, but for defense in depth, delete or restrict
   the `public_html/install/` directory via FTP.
6. **Enable SSL.** Turn on your host's SSL certificate for the domain,
   then edit `private_html/config.php` and set `'force_https' => true,`
   so plain HTTP requests redirect to HTTPS.
7. **Log in** at `https://yourdomain/login.php` with the admin account
   you created, then use *Manage users* to create accounts for anyone
   else who needs access - there's no public sign-up page.

### Backups

This holds sensitive financial data. Use your host's MySQL backup tools
(or `mysqldump`) on a schedule, and keep a copy of
`private_html/config.php` somewhere safe (it is not stored in this
repo).

### Recurring items

Preset items marked "carry forward" (on the Categories page) are
generated automatically the first time you open the dashboard or
transactions page for that period - no cron job is required.

## Local development

```
composer install --no-dev   # only needed if you edit private_html/composer.json
php -S 127.0.0.1:8000 -t public_html
```

Then visit `http://127.0.0.1:8000/install/` against a local MySQL/MariaDB
database to set up a dev instance.
