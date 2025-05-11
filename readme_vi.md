<p align="center">
    <img src="https://static.gp247.net/logo/logo.png" width="150">
</p>
<p align="center">Core Laravel admin cho tất cả các hệ thống (thương mại điện tử, cms, pmo...)<br>
    <code><b>composer require gp247/core</b></code></p>
<p align="center">
 <a href="https://gp247.net">Hướng dẫn cài đặt và tài liệu</a>
</p>

<p align="center">
<a href="https://packagist.org/packages/gp247/core"><img src="https://poser.pugx.org/gp247/core/d/total.svg" alt="Tổng lượt tải"></a>
<a href="https://packagist.org/packages/gp247/core"><img src="https://poser.pugx.org/gp247/core/v/stable.svg" alt="Phiên bản ổn định mới nhất"></a>
<a href="https://packagist.org/packages/gp247/core"><img src="https://poser.pugx.org/gp247/core/license.svg" alt="Giấy phép"></a>
</p>

## Giới thiệu về GP247
GP247 là một mã nguồn nhỏ gọn được xây dựng với Laravel, giúp người dùng nhanh chóng xây dựng một trang web quản trị mạnh mẽ. Dù hệ thống của bạn đơn giản hay phức tạp, GP247 sẽ giúp bạn vận hành và mở rộng nó một cách dễ dàng.

**GP247 có thể làm gì?**

- Cung cấp giải pháp quản lý vai trò và nhóm người dùng mạnh mẽ và linh hoạt.
- Cung cấp API xác thực đồng bộ, tăng cường bảo mật API với các lớp bổ sung.
- Xây dựng và quản lý các Plugin/Template hoạt động trong hệ thống
- Hệ thống giám sát nhật ký truy cập toàn diện.
- Liên tục cập nhật các lỗ hổng bảo mật.
- Hỗ trợ đa ngôn ngữ, dễ dàng quản lý.
- GP247 là MIỄN PHÍ

**Và nhiều hơn nữa:**

- GP247 xây dựng một hệ sinh thái mở rộng lớn (plugin, template), giúp người dùng nhanh chóng xây dựng CMS, PMO, thương mại điện tử, v.v., theo nhu cầu của bạn.

<p align="center">
    <img src="https://static.gp247.net/page/gp247-screen.jpg" width="100%">
</p>

## Core Laravel:

GP247 1.x

> Core laravel framework 12.x 


## Cấu trúc website sử dụng GP247

    Website-folder/
    |
    ├── app
    │     └── GP247
    │           ├── Core(+) //Tùy chỉnh controller của Core
    │           ├── Helpers(+) //Tự động tải Helpers/*.php
    │           ├── Plugins(+) //Sử dụng `php artisan gp247:make-plugin --name=TenPlugin`
      //(NẾU bạn đã cài đặt gp247/front)//
    │           ├── Front(+) //Tùy chỉnh controller của Front 
      //(NẾU bạn đã cài đặt gp247/shop)//
    │           ├── Shop(+) //Tùy chỉnh controller của Shop 
    │           └── Templates(+) //Sử dụng `php artisan gp247:make-template --name=TenTemplate`
    ├── public
    │     └── GP247
    │           ├── Core(+)
    │           ├── Plugins(+)
      //(NẾU bạn đã cài đặt gp247/front)//
    │           └── Templates(+)
    ├── resources
    │            └── views/vendor
    │                           |── gp247-core(+) //Tùy chỉnh view core
    │                           └── gp247-front(+) //(NẾU bạn đã cài đặt gp247/front)//
    ├── vendor
    │     ├── gp247/core
    │     └── gp247/front
    ├── .env
    │     └── GP247_ACTIVE=1 //BẬT|TẮT gp247
    └──...


## Hướng dẫn cài đặt nhanh
- **Bước 1**: Chuẩn bị source Laravel

  Tham khảo lệnh: 
  >`composer create-project laravel/laravel website-folder`

- **Bước 2**: Cài đặt gói gp247/core

  Di chuyển vào thư mục Laravel (trong ví dụ này là `website-folder`), và chạy lệnh:

  >`composer require gp247/core`

- **Bước 3**: Kiểm tra cấu hình trong file .env

  Đảm bảo thông tin cấu hình database và APP_KEY trong file .env đã đầy đủ.

  Nếu APP_KEY chưa được thiết lập, sử dụng lệnh sau để tạo: 
  >`php artisan key:generate`

- **Bước 4**: Cấu hình database
  
Mặc định, GP247 sử dụng mysql. Cấu hình sẽ được lưu trong file .env như sau:
```
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=gp247
  DB_USERNAME=root
  DB_PASSWORD=
```

  Nếu bạn muốn sử dụng sqlite để kiểm tra nhanh, vui lòng thay đổi connection trong file .env thành sqlite, và comment các dòng DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.
  
```
    DB_CONNECTION=sqlite
    #DB_HOST=127.0.0.1
    #DB_PORT=3306
    #DB_DATABASE=gp247
    #DB_USERNAME=root
    #DB_PASSWORD=
```
- **Bước 5**: Khởi tạo gp247

  Chạy lệnh: 
  >`php artisan gp247:core-install`

- **Bước 6**: Thêm xử lý lỗi

  Để thêm xử lý lỗi tùy chỉnh cho ứng dụng của bạn, hãy mở file `bootstrap/app.php` và thêm đoạn mã sau vào hàm `withExceptions`:

  ```php
  ->withExceptions(function (Exceptions $exceptions) {
      $exceptions->report(function (\Throwable $e) {
          if (function_exists('gp247_handle_exception')) {
              gp247_handle_exception($e);
          }
      });
  });
  ```

  Đoạn mã này sẽ giúp bạn xử lý các ngoại lệ thông qua hàm `gp247_handle_exception` nếu hàm này tồn tại.
## Thông tin hữu ích:

**Để xem phiên bản GP247**

>`php artisan gp247:core-info`

**Cập nhật gp247**

Cập nhật gói bằng lệnh: 
>`composer update gp247/core`

Sau đó, chạy lệnh: 

>`php artisan gp247:core-update`

**Để tạo plugin:**

>`php artisan gp247:make-plugin  --name=TenPlugin`

Để tạo file zip plugin

>`php artisan gp247:make-plugin  --name=TenPlugin --download=1`

**Để tạo template (`NẾU bạn đã cài đặt gp247/front`):**

>`php artisan gp247:make-template  --name=TenTemplate`

Để tạo file zip template:


>`php artisan gp247:make-template  --name=TenTemplate --download=1`

## Tùy chỉnh


**Tùy chỉnh cấu hình lfm dành cho upload**

>`php artisan vendor:publish --tag=config-lfm`

**Tùy chỉnh view của core admin**

>`php artisan vendor:publish --tag=gp247:view-core`


**Ghi đè các hàm helper gp247_***

>Bước 1: Thêm danh sách các hàm muốn ghi đè vào `config/gp247_functions_except.php`

>Bước 2: Tạo các file php chứa các hàm mới trong thư mục `app/GP247/Helpers`, ví dụ `app/GP247/Helpers/myfunction.php`

**Ghi đè các file controller gp247**

>Bước 1: Copy các file controller muốn ghi đè trong vendor/gp247/core/src/Core/Controllers -> app/GP247/Core/Controllers

>Bước 2: Thay đổi `namespace GP247\Core\Controllers` thành `namespace App\GP247\Core\Controllers`

**Ghi đè các file controller API gp247**

>Bước 1: Copy các file controller muốn ghi đè trong vendor/gp247/core/src/Api/Controllers -> app/GP247/Core/Api/Controllers

>Bước 2: Thay đổi `namespace GP247\Core\Api\Controllers` thành `namespace App\GP247\Core\Api\Controllers`

## Thêm route

Sử dụng các hằng số prefix và middleware `GP247_ADMIN_PREFIX`, `GP247_ADMIN_MIDDLEWARE` trong khai báo route.

Tham khảo: https://github.com/gp247net/core/blob/master/src/routes.php

## Các biến môi trường trong file .env

**Nhanh chóng tắt GP247 và plugins**
> `GP247_ACTIVE=1` // Để tắt, đặt giá trị 0

**Tắt APIs**
> `GP247_API_MODE=1` // Để tắt, đặt giá trị 0

**Tiền tố bảng dữ liệu**
> `GP247_DB_PREFIX=gp247_` //Không thể thay đổi sau khi cài đặt gp247

**Tiền tố đường dẫn đến admin**
> `GP247_ADMIN_PREFIX=gp247_admin` 