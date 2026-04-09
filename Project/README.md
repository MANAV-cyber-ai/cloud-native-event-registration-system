# Cloud Event Registration Portal (Auth + Role Based)

Built with `HTML + CSS + PHP + MySQL` and designed for XAMPP.

Database name: **`cloud_event_registration_db`**

## 1) XAMPP Path You Asked
Use this XAMPP path:


Project folder path:

```text
C:\xampp_Data\htdocs\Project
```

## 2) What To Run For Full Database Setup
Open PowerShell in project folder and run:

```powershell
& 'C:\xampp_Data\mysql\bin\mysql.exe' -u root -e "SOURCE db/schema.sql"
```

This command creates:
- full schema
- all tables
- views
- seed events
- auth users

## 3) Default Login Credentials
- Admin username: `Admin`
- Admin password: `Admin`

Also seeded demo client:
- Client username: `studentdemo`
- Client password: `Client@123`

## 4) Which HTML/PHP Pages To Open
Use these URLs in browser:

- Home page: `http://localhost/Project/index.php`
- HTML Login page: `http://localhost/Project/login.html`
- HTML Registration page: `http://localhost/Project/register.html`
- HTML Forgot Password page: `http://localhost/Project/forgot-password.html`
- HTML Change Password page: `http://localhost/Project/change-password.html`
- HTML Admin Dashboard entry page: `http://localhost/Project/admin-dashboard.html`
- HTML Client Dashboard entry page: `http://localhost/Project/client-dashboard.html`
- Admin login: `http://localhost/Project/login.php?role=ADMIN`
- Client login: `http://localhost/Project/login.php?role=CLIENT`
- Client registration: `http://localhost/Project/register.php?role=CLIENT`
- Admin registration: `http://localhost/Project/register.php?role=ADMIN`
- Forgot password: `http://localhost/Project/forgot-password.php`
- Change password (after login): `http://localhost/Project/change-password.php`
- Admin dashboard (after admin login): `http://localhost/Project/admin.php`
- Client portal (after client login): `http://localhost/Project/client.php`

## 5) Implemented Auth Features
- Separate login for Admin and Client
- Registration page for Admin and Client
- Session-based protected routes
- Forgot password page
- Change password page
- Logout flow
- Password reset audit log in database

## 6) Database Entities (Proper + Detailed)
Master tables:
- `event_categories`
- `departments`
- `venues`
- `event_coordinators`

Transactional tables:
- `events`
- `students`
- `registrations`
- `event_coordinator_map`
- `registration_activity_log`
- `notification_outbox`

Authentication tables:
- `auth_users`
- `auth_password_reset_log`

Views:
- `v_registration_overview`
- `v_event_capacity`

## 7) Folder Structure
```text
Project/
|-- index.php
|-- login.php
|-- register.php
|-- forgot-password.php
|-- change-password.php
|-- logout.php
|-- client.php
|-- admin.php
|-- api/
|   |-- get_events.php
|   |-- get_form_meta.php
|   `-- register.php
|-- assets/
|   |-- css/
|   |   |-- auth.css
|   |   |-- styles.css
|   |   `-- admin.css
|-- config/
|   |-- bootstrap.php
|   |-- database.php
|   `-- auth.php
|-- db/
|   `-- schema.sql
|-- docs/
|   |-- ppt-outline.md
|   `-- architecture-diagram.mmd
`-- README.md
```

## 8) Quick Start
1. Start `Apache` and `MySQL` from `C:\xampp_Data\xampp-control.exe`.
2. Run DB import command shown above.
3. Open `http://localhost/Project/index.php`.

## 9) Important Notes
- Frontend is browser-openable HTML/CSS pages (`index.php`, `login.php`, `register.php`, `client.php`, `admin.php`).
- Client registration on `client.php` now uses normal server-side HTML form submit (no JavaScript required).
- JavaScript is not required for page rendering or form submission.
- Re-running `db/schema.sql` resets tables to clean state for demo.
