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
- requireCore: Là phiên bản Gp247/Core phù hợp với extension.
- requirePackages: Các package (từ packagist.org) được yêu cầu cài đặt
- requireExtensions: Tên các extension của GP247 (plugin, template) được yêu cầu cài đặt. Ví dụ: Shop, Front,News,...

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
