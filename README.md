# School RFID Attendance System (PHP + MySQL)

This project is a working starter system for a **school RFID attendance** platform using:

- PHP (plain PHP, no framework)
- MySQL / MariaDB (managed through phpMyAdmin)
- Bootstrap 5 UI
- Multi-tenant SaaS architecture

## Implemented Features

- Dashboard
  - number of students (male/female/other)
  - number of teachers + employees (male/female/other)
  - number of parents
  - attendance graph (Chart.js) + table (last 7 days)
- Users
  - list of students
  - list of teachers/employees
  - list of parents
  - upload and manage user/student profile pictures
  - create student accounts with extended profile fields
  - bulk import students via CSV template
  - update information
  - update credentials
  - parent assign to students
- RFID
  - add list of RFID cards
  - assign RFID card to user
  - remove assignment
  - scanner endpoint for attendance logging
  - full-screen gate scanner UI (`public/gate.php`)
  - scanned user profile card on gate (photo + role + academe info)
  - auto IN/OUT toggle when `scan_type` is not provided
  - duplicate-scan protection
  - late detection for student time-in
- User logs report
  - filter per date
  - filter per grade level
  - filter per section
  - filter per student/teacher/employee
  - export to Excel (CSV)
  - export to PDF (print page -> Save as PDF)
  - print
- Academe
  - course
  - grade level
  - section
- Announcement
  - create/update announcements
  - toggle active/inactive
- Authentication and access control
  - login/logout
  - role-based page permissions
- SaaS / Multi-tenant
  - each school is a tenant
  - data isolation per tenant (users, RFID, logs, announcements, academe)
  - tenant slug login (`?tenant=your-school`)
  - school branding settings (name/logo/gate background)
  - platform admin dashboard (manage all tenants, plans, billing, limits)
  - automatic subscription enforcement (trial expiration auto-suspends tenant)
  - billing and usage CSV report per tenant

## Project Structure

- `config/config.php` - app and DB settings
- `sql/schema.sql` - database schema for phpMyAdmin import
- `sql/saas_upgrade.sql` - migration script for existing non-SaaS DB
- `public/` - all pages/modules
- `includes/` - database, helpers, layout

## Setup Instructions

1. Create database using phpMyAdmin:
   - Open phpMyAdmin.
   - Import file: `sql/schema.sql`.
   - If you already imported an older schema before SaaS update, run `sql/saas_upgrade.sql` (includes profile photo column migration).
2. Configure DB connection in `config/config.php`:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Run local PHP server:
   - From project root:
     - `php -S localhost:8000`
4. Open:
   - [http://localhost:8000](http://localhost:8000)
   - You can also pass tenant slug:
     - [http://localhost:8000/?tenant=default-school](http://localhost:8000/?tenant=default-school)
   - Platform admin:
     - [http://localhost:8000/public/platform_login.php](http://localhost:8000/public/platform_login.php)

## Default Accounts

These are seeded by `sql/schema.sql`:

- Admin
  - Username: `admin`
  - Password: `admin123`
- Teacher
  - Username: `teacher1`
  - Password: `teacher123`
- Employee
  - Username: `employee1`
  - Password: `employee123`
- Parent
  - Username: `parent1`
  - Password: `parent123`

Tenant slug:

- `default-school`

Platform admin:

- Username: `superadmin`
- Password: `superadmin123`

## SaaS Super Admin

Use `public/platform_login.php` to access global SaaS controls:

- create new tenants
- auto-create tenant admin user on tenant creation
- configure plan name, max users, max RFID cards
- manage billing status (`trial`, `paid`, `past_due`, `suspended`)
- set tenant status (`active` / `inactive`)
- view usage counters (users, cards, scans today)
- run subscription automation manually
- export billing + usage report (`public/platform_billing_report.php`)

Subscription automation behavior:

- if `billing_status = trial` and `trial_ends_at` is in the past, tenant is auto-updated to:
  - `billing_status = suspended`
  - `status = inactive`

## RFID Scanner Integration

Device/scanner should send HTTP POST to:

- `http://localhost:8000/public/rfid_scan.php`

Gate page (for front-desk/guard screen):

- `http://localhost:8000/public/gate.php?tenant=default-school&gate=Gate%201`

POST fields:

- `uid` (required)
- `device_name` (optional)
- `scan_type` (optional: `IN` or `OUT`)
  - if omitted, system auto-detects IN/OUT

Sample POST (PowerShell):

```powershell
Invoke-RestMethod -Method Post -Uri "http://localhost:8000/public/rfid_scan.php" -Body @{
  uid = "A1B2C3D4"
  device_name = "Gate 1 Reader"
  scan_type = "IN"
}
```

## Notes

- Export "Excel" uses CSV format for compatibility.
- Export "PDF" uses browser print page. Use **Print -> Save as PDF**.
- Late cutoff and scan interval settings are configurable in `config/config.php`.
