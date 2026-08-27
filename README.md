# Student Concern Reporting System

A web-based system that lets students at **Camarines Sur Polytechnic Colleges (CSPC)** submit concerns, which are automatically routed to the correct office and handled with strict access control, privacy protection, and a full audit trail.

The system balances two goals that are usually in tension: **protecting students** who report sensitive issues, and **maintaining accountability** so that false or malicious reports can be investigated.

Built as a capstone project with **Laravel 11** and **PHP 8.4**.

---

## Features

- **Category-based routing** — concerns are automatically assigned to Faculty/Staff, a Guidance Counselor, or Admin based on their category.
- **Least-privilege visibility** — a single authorization rule controls who can see each concern; users see only what their role needs.
- **Referral lifecycle** — a handler can refer a concern to another role; ownership transfers so the new handler can resolve it.
- **Anonymous reporting** — students can report without their name being shown to handlers; identity is stored securely.
- **Conflict-of-interest protection** — a concern about a staff member is routed away from that person, and they cannot even view it.
- **Break-glass identity reveal** — only the Head of School can reveal an anonymous reporter, with a required reason, and every reveal is permanently logged.
- **Secure evidence attachments** — optional files (JPG/PNG/PDF), stored privately with randomized names, access-controlled like the concern itself.
- **Guided reporting** — a description guide helps students write clear, useful reports.
- **Analytics dashboard** — institution-wide trend counts, decoupled from record access.
- **Audit trail** — every significant action is logged with who, what, and when.
- **Public confidentiality policy page** — publishes the privacy guarantees to students and staff.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 11 (PHP 8.4) |
| Database | SQLite |
| Frontend | Blade templates + CSS |
| Testing | PHPUnit (83 automated tests) |

---

## Requirements

- PHP 8.3 or higher
- [Composer](https://getcomposer.org/)

---

## Setup

Clone the repository and move into the folder:

```bash
git clone https://github.com/grim-reaper-bit/student-concern-reporting-system.git
cd student-concern-reporting-system
```

Install dependencies:

```bash
composer install
```

Create your environment file and generate an application key:

```bash
copy .env.example .env      # on Windows
# cp .env.example .env      # on macOS/Linux
php artisan key:generate
```

Create the SQLite database file:

```bash
# Windows (PowerShell)
New-Item database\database.sqlite -ItemType File

# macOS/Linux
touch database/database.sqlite
```

Run the migrations and seed the demo data:

```bash
php artisan migrate --seed
```

Start the development server:

```bash
php artisan serve
```

Then open the URL shown in the terminal (usually `http://127.0.0.1:8000`).

---

## Demo Accounts

All demo accounts use the password: **`password`**

| Email | Role |
|-------|------|
| student@my.cspc.edu.ph | Student |
| student2@my.cspc.edu.ph | Student |
| staff@my.cspc.edu.ph | Faculty / Staff |
| counselor@my.cspc.edu.ph | Guidance Counselor |
| rosel.onesa@cspc.edu.ph | Department Head — College of Computer Studies |
| martin.valeras@cspc.edu.ph | Department Head — College of Engineering and Architecture |
| maria.iglesia@cspc.edu.ph | Department Head — College of Tourism, Hospitality and Business Management |
| kenny.tagum@cspc.edu.ph | Department Head — College of Health Sciences |
| patrick.paulino@cspc.edu.ph | Department Head — College of Technological and Development Education |
| marlon.pontillas@cspc.edu.ph | Department Head — College of Arts and Sciences |
| admin@my.cspc.edu.ph | Admin |
| head@my.cspc.edu.ph | Head of School |

---

## Running the Tests

The system includes an automated test suite covering routing, authorization, privacy, conflict-of-interest, break-glass reveals, file-upload security, and hostile security scenarios (IDOR, privilege escalation, XSS, brute force).

```bash
php artisan test
```

Expected result: **83 passing tests.**

---

## Security Highlights

- Least-privilege access enforced by a single shared authorization rule
- Anonymous reporting with logged, restricted identity reveals
- Reported staff hard-excluded from concerns about themselves
- File uploads whitelisted by type, size- and count-capped, privately stored, and access-controlled on download
- Server-side validation on all input, CSRF protection on all forms, hashed passwords, and rate-limited login

---

## Notes

- `APP_DEBUG` is enabled for development. For a production deployment it should be set to `false`.
- The project uses SQLite for simplicity. For production, a server database such as MySQL or PostgreSQL is recommended.
- Uploaded evidence files and the database file are intentionally excluded from version control.

---

## License

This project was developed as an academic capstone project for Camarines Sur Polytechnic Colleges.