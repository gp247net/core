# Tạo Plugin mới

Để tạo một plugin mới, sử dụng lệnh artisan sau:

```bash
php artisan gp247:make-plugin --name=YourPluginName --download=0
```

Trong đó:
- `YourPluginName`: Tên plugin của bạn
- `--download=0`: Tạo plugin trực tiếp trong thư mục app/GP247/Plugins
- `--download=1`: Tạo file zip plugin trong thư mục storage/tmp


# Cấu trúc Plugin GP247

Đây là template chuẩn cho việc phát triển plugin trong hệ thống GP247. Plugin được thiết kế theo mô hình MVC (Model-View-Controller) và tuân thủ các quy tắc của Laravel framework.

## Cấu trúc thư mục

```
plugin/
├── Admin/           # Chứa các file liên quan đến quản trị
├── Controllers/     # Chứa các controller xử lý logic
├── Lang/           # Chứa các file ngôn ngữ
├── Models/         # Chứa các model
├── public/         # Chứa các file public (css, js, images). Khi cài đặt, sẽ được copy tới publi/GP247/Plugins/Your-plugin
├── Views/          # Chứa các file view
├── AppConfig.php   # File cấu hình chính của plugin
├── config.php      # File cấu hình
├── function.php    # Chứa các hàm helper
├── gp247.json      # File khai báo thông tin plugin
├── Provider.php    # Service provider của plugin
├── Route.php       # Định nghĩa routes
└── route_front.stub # Template cho route frontend
```

## Các file chính

### 1. gp247.json
File khai báo thông tin cơ bản của plugin:
- name: Tên plugin
- image: Logo plugin
- auth: Tác giả
- configGroup: Nhóm cấu hình
- configCode: Mã cấu hình
- configKey: Khóa cấu hình, là giá trị duy nhất, trùng vói tên folder Plugin
- version: Phiên bản
- requireCore: Là phiên bản Gp247/Core phù hợp với extension (chuẩn hiện tại để ["2.1"]).
- requireUpdateFrom: Phiên bản đang cài tối thiểu được phép cập nhật 1-click lên bản này. Mặc định bằng version của scaffold (thực tế không giới hạn gì); hãy nâng lên khi phát hành bản major mà hook update() không migrate được từ dòng cũ — ví dụ đặt "2.0" cho bản 2.9 để chặn cập nhật từ bản 1.x. Bỏ trống nếu không giới hạn.
- requireComposerPackages: Các gói Composer (từ packagist.org) được yêu cầu cài đặt (vd gp247/front). Đổi tên từ `requirePackages` ở gp247/core 2.1 (khóa cũ core vẫn đọc nhưng đã deprecated).
- requireGp247Extensions: Tên các extension của GP247 (plugin, template) được yêu cầu đã cài. Ví dụ: Shop, Front, News,... Đổi tên từ `requireExtensions` ở gp247/core 2.1 (khóa cũ core vẫn đọc nhưng đã deprecated).

### 2. AppConfig.php
File cấu hình chính của plugin, chứa các phương thức:
- install(): Cài đặt plugin
- uninstall(): Gỡ cài đặt plugin
- enable(): Kích hoạt plugin
- disable(): Vô hiệu hóa plugin
- setupStore(): Thiết lập cho store
- removeStore(): Xóa thiết lập store
- clickApp(): Xử lý khi click vào plugin trong admin
- getInfo(): Lấy thông tin plugin

### 3. Provider.php
Service provider của plugin, đăng ký các service và middleware.

### 4. Route.php
Định nghĩa các route cho plugin.

### 5. config.php & function.php — Cấu hình người dùng an toàn khi update (chuẩn #7)
Cập nhật plugin 1-click **ghi đè toàn bộ file** của plugin nhưng **giữ nguyên `admin_config` (DB)** — xem ADR `plugin-manager_extension-update-flow`, RISK-OPS-plugin-config-file-overwrite. Do đó:
- **`config.php` = chỉ chứa DEFAULT** (do package sở hữu, bị ghi đè khi update). Đặt giá trị mặc định bất biến và cấu hình cấp-dev ở đây.
- **Mọi giá trị do CHỦ SITE chỉnh** (bật/tắt, tinh chỉnh) phải lưu ở `admin_config` và overlay lên default lúc runtime — nếu không, các lựa chọn đó sẽ bị reset về default của bản mới sau mỗi lần update.
- `AppConfig::uninstall()` của scaffold đã dọn sẵn row `admin_config` có `code` là `<configKey>_config`; đó là slot quy ước cho blob override. `function.php` ship sẵn helper (đang comment) `*_effective_config()` / `*_save_config()` cài đặt overlay `default(file) ⊕ override(DB)` — bỏ comment và chỉnh lại khi plugin của bạn có cấu hình chủ-site sửa được, hoặc xoá nếu không có.
- Bản tham chiếu: plugin `MFA` (guard `enabled`/`forced` + tunables ở `admin_config`; `config.php` chỉ giữ default model/redirect).

## Cách sử dụng

1. Tạo plugin mới:
   - Đổi tên thư mục theo tên template (trùng giá trị configKey)
   - Cập nhật thông tin trong gp247.json
2. Phát triển:
   - Thêm logic vào Controllers
   - Tạo model trong Models
   - Tạo view trong Views
   - Thêm ngôn ngữ trong Lang
   - Thêm assets trong public

3. Cài đặt:
   - Vui lòng tham khảo hướng dẫn cài đặt chi tiết tại: https://gp247.net/en/user-guide-extension/guide-to-installing-the-extension.html


## Lưu ý

- Tuân thủ cấu trúc MVC
- Sử dụng namespace đúng chuẩn
- Đảm bảo đa ngôn ngữ
- Kiểm tra các dependency trước khi cài đặt
- Xử lý lỗi và rollback khi cần thiết
