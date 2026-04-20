# Uninav Society - Residential Society Management

A complete, self-contained PHP/MySQL application for managing a residential
society. Deployable to Hostinger shared hosting over FTP.

- **Target URL:** `http://learninganddevelopment.net/Uninav/Application/`
- **Stack:** PHP 7.4+ / MySQL / vanilla JS / Chart.js via CDN

## Features

- Registration with **email OTP verification** (Tower A-G, House, Owner)
- Login (Resident / Admin / Guest)
- Forgot-password via OTP
- User profile (photo upload, change password, change email via OTP)
- **Event Calendar** (month view + popup details)
- **Society Notices** (search + filter, image attachments)
- **Complaints** with status workflow + email notifications +
  admin dashboard (tiles, charts, comments, status updates)
- **Finance & Expenses** (monthly tiles, expense details)
- **Third-party Vendors** across 5 categories with own-maid detection
  (by house) + one-click complaint-from-panel
- **Advertisements** (user posts, admin moderation, restrict users)
- **Polls** (MCQ, vote tracking, PDF export, email to residents)
- **Admin Settings** panel consolidating all management

## Credentials

| Role     | Username   | Password   |
| -------- | ---------- | ---------- |
| Admin    | `Uniadmin` | `99114212` |
| Guest    | `Guest`    | `Guest`    |
| Resident | (register) | (set)      |

## Installation

1. Upload the entire `Uninav/Application/` folder to your FTP root (so URLs
   resolve to `http://learninganddevelopment.net/Uninav/Application/...`).
2. Make sure the `uploads/` folder is writable (CHMOD 755 or 775).
3. In your browser open **once**:
   ```
   http://learninganddevelopment.net/Uninav/Application/install.php
   ```
   This creates every database table and seeds the admin + guest accounts.
4. **Delete `install.php`** after a successful run.
5. Log in: `/auth/login.php`.

## Database

- Host: `localhost`
- DB:   `u694536902_uninav`
- User: `u694536902_uninavGaurav`
- Pass: stored in `config/config.php`

The SQL schema is in `sql/schema.sql`.

## Complaint Routing

All new complaints are emailed to **gauravgosain@ymail.com** using PHP
`mail()`. Status updates also email the reporting resident.

## Notes

- Session cookies named `UNINAVSESS`.
- CSRF tokens are enforced on every POST.
- Uploads live under `uploads/{complaints,notices,vendors,ads,profiles,events}`.
- PHP execution in `uploads/` is blocked by `.htaccess`.
