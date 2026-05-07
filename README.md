# Student Management System

Hệ thống quản lý sinh viên cấp khoa, gồm các chức năng quản trị hồ sơ sinh viên/giáo viên, quản lý môn học - lớp học phần, nhập điểm, tra cứu điểm và thời khóa biểu.

## 1. Mục tiêu đề tài
- Số hóa nghiệp vụ quản lý sinh viên theo mô hình cổng thông tin đào tạo.
- Phân quyền rõ theo vai trò: `admin`, `manager`, `teacher`, `student`.
- Chuẩn hóa dữ liệu và hỗ trợ nhập liệu hàng loạt bằng file CSV.

## 2. Kiến trúc hệ thống
- Frontend: `Vue 3 + Vite` (thư mục `fe`).
- Backend: `PHP` (router tại `api/index.php`).
- Cơ sở dữ liệu: `SQLite` (file `api/storage/ltweb.sqlite`).

## 3. Chức năng chính
### 3.1. Nhóm quản trị (`admin`/`manager`)
- Nhập hồ sơ sinh viên, giáo viên (đơn lẻ + hàng loạt CSV).
- Tìm kiếm, xem chi tiết, cập nhật, xóa sinh viên/giáo viên.
- Tạo lớp học phần, quản lý môn học, cập nhật danh sách sinh viên theo môn.
- Theo dõi yêu cầu quên mật khẩu.
- `admin` có quyền cấp lại mật khẩu (reset về mặc định).

### 3.2. Giáo viên (`teacher`)
- Xem hồ sơ cá nhân, cập nhật hồ sơ.
- Xem các môn được phân công.
- Nhập điểm CC/GK/CK theo trọng số cho sinh viên trong lớp học phần.

### 3.3. Sinh viên (`student`)
- Xem hồ sơ cá nhân, cập nhật hồ sơ.
- Xem danh sách môn đang học.
- Xem điểm thi cá nhân và GPA.
- Xem thời khóa biểu theo khung tiết.

## 4. Cấu trúc thư mục
```text
Student_Web/
├─ api/                 # Backend PHP + SQLite
│  ├─ app/              # Model/Controller/Common
│  ├─ storage/          # DB + script migrate
│  └─ index.php         # Router entrypoint
├─ fe/                  # Frontend Vue
├─ Danh_sach_*.csv      # Dữ liệu mẫu import
└─ README.md
```

## 5. Yêu cầu môi trường
- PHP `>= 8.1` (khuyến nghị 8.2), bật `pdo_sqlite`.
- Node.js theo `fe/package.json` (`^20.19.0 || >=22.12.0`).
- `pnpm` (hoặc `npm`, tùy môi trường).

## 6. Hướng dẫn chạy dự án
Chạy từ thư mục gốc `D:\K2_N4\Student_Web`.

### 6.1. Backend
```bash
cd api
php -S localhost:8000
```

### 6.2. Frontend
```bash
cd fe
pnpm install
pnpm run dev
```

Mặc định:
- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`

## 7. Cơ sở dữ liệu và migrate
- DB đang dùng: `api/storage/ltweb.sqlite`
- Script migrate chính:
  - `api/storage/migrate_studentmanagement_design.php`: migrate theo thiết kế Student Management.
  - `api/storage/replace_old_schema_with_studentmanagement.php`: thay thế schema cũ sang schema mới.
  - `api/storage/migrate_normalized_schema.php`: script chuẩn hóa trước đây (phục vụ đối chiếu/refactor).

Ví dụ chạy migrate:
```bash
php api/storage/migrate_studentmanagement_design.php
```

## 8. Định dạng dữ liệu nhập CSV (tóm tắt)
- Sinh viên: `MSSV, Họ tên, Ngày sinh, Giới tính, Lớp, Khoa/Viện, Email, SĐT, Trạng thái`
- Giáo viên: `MSGV, Họ tên, Ngày sinh, Giới tính, Khoa/Bộ môn, Email, Lớp phụ trách, SĐT, Trạng thái`
- Môn học/lớp học phần: mã môn, tên môn, tín chỉ, MSGV, khoa, lịch học, phòng học, sĩ số tối đa.

Lưu ý:
- Lịch học dùng định dạng `Tn-(a-b)` (ví dụ `T2-(1-3)`).
- `CaHoc` trong bảng `ThoiKhoaBieu` được lưu kiểu chuỗi (TEXT), biểu diễn khoảng tiết `a-b`.

## 9. Ghi chú phát triển
- Ưu tiên giữ tương thích API hiện tại khi refactor.
- Các thông báo lỗi và giao diện dùng tiếng Việt có dấu.
