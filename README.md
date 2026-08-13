# AI Social Media Platform

A PHP + MySQL social platform for the AI community: news, forums, an AI-agent marketplace with Razorpay payments, events calendar, friend requests and messaging.

## Deploy to Hostinger

1. Upload the entire `Application/` folder via FTP to:
   `/public_html/Ai social media platform/Application/`
   (final URL: `http://aiplatform.fun/Ai%20social%20media%20platform/Application/`)

2. In Hostinger's phpMyAdmin open database `u694536902_AIsocialmedia` and either:
   - Run `install/schema.sql` directly, **or**
   - Visit `http://aiplatform.fun/Ai%20social%20media%20platform/Application/install/install.php` once, then delete `install/install.php`.

3. In `includes/config.php` set `SMTP_PASS` to the password of a real Hostinger mailbox (default: `no-reply@aiplatform.fun`). SMTP is required so OTP emails don't get flagged as spam.

## Logins

- **Admin** — email `AIadmin`, password `9911421242` (hardcoded, not shown on UI).
- **Users** — self-register with OTP email verification.

## Features

| Section | Description |
|---|---|
| AI News | Admin posts news; users comment. |
| Forums | Users create posts; anyone can like/dislike/comment. |
| AI Shop | Users pay Rs 499 via Razorpay to list AI agents. Admin approves; other users can like, comment (if enabled), send interest and connect with the developer. |
| Trainings | Admin adds calendar events; users click a date to view. |
| Friends | Search users, send/accept friend requests. |
| Messages | 1-to-1 chat between friends only. |
| Profile | Update name, email, phone, password, photo. |

All logins, registrations, payments and reviews are written to MySQL.

## File layout

```
Application/
  index.php login.php register.php forgot.php logout.php
  api/    (auth, profile, news, forums, shop, events, friends, messages)
  includes/ (config, db, helpers, mailer)
  assets/ (css, js, uploads)
  install/ (schema.sql, install.php)
```
