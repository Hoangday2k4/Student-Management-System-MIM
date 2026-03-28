# LT-WEB-NO1

## Database check (SQLite)
- Use `api/db_check.php` to verify PHP has `pdo_sqlite` enabled and the SQLite file is reachable.
- Run it once during setup or when you see DB connection errors (not required after every change).

## Chuan hoa schema theo ERD
- Da co script: `api/storage/migrate_normalized_schema.php`
- Script se tao/cap nhat cac bang chuan hoa:
  - `accounts`, `departments`, `classes`, `course_sections`, `enrollments`, `scores`, `device_transactions`
  - bo sung cot lien ket cho bang cu: `students.account_id/class_id`, `teachers.account_id/department_id`, `courses.department_id`
- Chay script:

```bash
php api/storage/migrate_normalized_schema.php
```

- Luu y: script uu tien khong pha vo API hien tai (giu nguyen bang cu), dong bo du lieu sang cau truc chuan hoa de ban refactor API dan.

## Run with npm/pnpm (development)

2. From the project root run:

```bash
pnpm run start
```

This runs the backend and frontend servers.

- The frontend server runs on port 5173 by default.
- The backend server runs on port 8000 by default.


link commit:https://docs.google.com/spreadsheets/d/1wBdMGBdLOZ0Jwtr2grzdQX4FZEE7z0wh3MfpcJefhkU/edit?gid=0#gid=0
