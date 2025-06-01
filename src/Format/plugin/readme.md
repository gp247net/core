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

---

# Create New Plugin

To create a new plugin, use the following artisan command:

```bash
php artisan gp247:make-plugin --name=YourPluginName --download=0
```

Where:
- `YourPluginName`: Your plugin name
- `--download=0`: Create plugin directly in app/GP247/Plugins directory
- `--download=1`: Create plugin zip file in storage/tmp directory


# GP247 Plugin Structure

This is the standard template for developing plugins in the GP247 system. The plugin is designed following the MVC (Model-View-Controller) pattern and adheres to Laravel framework rules.

## Directory Structure

```
plugin/
├── Admin/           # Contains admin-related files
├── Controllers/     # Contains logic handling controllers
├── Lang/           # Contains language files
├── Models/         # Contains models
├── public/         # Contains public files (css, js, images). When installed, will be copied to public/GP247/Plugins/Your-plugin
├── Views/          # Contains view files
├── AppConfig.php   # Main plugin configuration file
├── config.php      # Configuration file
├── function.php    # Contains helper functions
├── gp247.json      # Plugin information declaration file
├── Provider.php    # Plugin service provider
├── Route.php       # Route definitions
└── route_front.stub # Frontend route template
```

## Key Files

### 1. gp247.json
File declaring basic plugin information:
- name: Plugin name
- image: Plugin logo
- auth: Author
- configGroup: Configuration group
- configCode: Configuration code
- configKey: Configuration key, must be unique and match the plugin folder name
- version: Version
- requireCore: Compatible Gp247/Core version
- requirePackages: Required packages from packagist.org
- requireExtensions: Required GP247 extensions (plugins, templates). Example: Shop, Front, News,...

### 2. AppConfig.php
Main plugin configuration file, contains methods:
- install(): Install plugin
- uninstall(): Uninstall plugin
- enable(): Enable plugin
- disable(): Disable plugin
- setupStore(): Setup for store
- removeStore(): Remove store setup
- clickApp(): Handle when clicking plugin in admin
- getInfo(): Get plugin information

### 3. Provider.php
Plugin service provider, registers services and middleware.

### 4. Route.php
Defines plugin routes.

## Usage

1. Create new plugin:
   - Rename directory to match template name (must match configKey value)
   - Update information in gp247.json

2. Development:
   - Add logic to Controllers
   - Create models in Models
   - Create views in Views
   - Add languages in Lang
   - Add assets in public

3. Installation:
   - Please refer to detailed installation guide at: https://gp247.net/en/user-guide-extension/guide-to-installing-the-extension.html


## Notes

- Follow MVC structure
- Use correct namespace
- Ensure multilingual support
- Check dependencies before installation
- Handle errors and rollback when necessary
