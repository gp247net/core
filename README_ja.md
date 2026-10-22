```
  _____  _____     ___  _  _   _____ 
 / ____|  __ \   |__ \| || | |___  |
| |  __| |__) |     ) | || |_   / / 
| | |_ |  ___/     / /|__   _| / /  
| |__| | |        / /_   | |  / /   
 \_____|_|       |____|  |_| /_/    
```

> 🌐 **言語:** [🇻🇳 Tiếng Việt](./README_vi.md) · [🇬🇧 English](./README.md) · 🇯🇵 日本語（現在）

core のインストール（Laravel が既にある場合）：

> composer require gp247/core

[ホーム](https://gp247.net) | [公式ドキュメント](https://github.com/gp247net/gp247-docs) | [公式エージェントスキル](https://github.com/gp247net/gp247-skills) | [公式ファンページ](https://www.facebook.com/GP247.official/)

[![総ダウンロード数](https://poser.pugx.org/gp247/core/d/total.svg)](https://packagist.org/packages/gp247/core)
[![最新の安定版](https://poser.pugx.org/gp247/core/v/stable.svg)](https://packagist.org/packages/gp247/core)
[![ライセンス](https://poser.pugx.org/gp247/core/license.svg)](https://packagist.org/packages/gp247/core)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/gp247net/core)

## GP247 について
GP247 は、Laravel をベースに構築された、強力・安全・柔軟で、AI エージェントとの親和性が高いオープンソースシステムです。

GP247 エコシステムの主な構成要素：

- **[Package] GP247/core**：エコシステム全体のコアです。GP247/core は、単独で Laravel 管理画面として動作させることもできます。
- **[Package] GP247/front**：企業向け CMS の基本機能を提供するパッケージです。
- **[Package] GP247/shop**：EC サイトに必要な機能をすべて提供します。

 `[Project] S-Cart = GP247/core + GP247/front + GP247/shop`


## Laravel コア：

GP247 3.x

> Core laravel framework 13.x 


## GP247 を使用したウェブサイトの構成

    Website-folder/
    |
    ├── app
    │     └── GP247
    │           ├── Core(+) //Core のコントローラーをカスタマイズ
    │           ├── Helpers(+) //Helpers/*.php を自動で読み込み
    │           ├── Plugins(+) //`php artisan gp247:make-plugin --name=PluginName` を使用
      //（gp247/front をインストール済みの場合）//
    │           ├── Front(+) //Front のコントローラーをカスタマイズ 
      //（gp247/shop をインストール済みの場合）//
    │           ├── Shop(+) //Shop のコントローラーをカスタマイズ 
    │           └── Templates(+) //`php artisan gp247:make-template --name=TemplateName` を使用
    ├── public
    │     └── GP247
    │           ├── Core(+)
    │           ├── Plugins(+)
      //（gp247/front をインストール済みの場合）//
    │           └── Templates(+)
    ├── resources
    │            └── views/vendor
    │                           |── gp247-admin(+) //core のビューをカスタマイズ
    │                           |── gp247-front-admin(+) //（gp247/front をインストール済みの場合）//
    │                           └── gp247-shop-admin(+) //（gp247/shop をインストール済みの場合）//
    ├── vendor
    │     ├── gp247/core
    │     ├── gp247/front
    │     └── gp247/shop
    ├── .env
    │     └── GP247_ACTIVE=1 //gp247 を有効|無効
    └──...


## クイックインストールガイド
- **ステップ 1**：Laravel のソースを用意します

  参考コマンド：
  >`composer create-project laravel/laravel website-folder`

- **ステップ 2**：gp247/core パッケージをインストールします

  Laravel のディレクトリ（この例では `website-folder`）に移動し、次のコマンドを実行します：

  >`composer require gp247/core`

- **ステップ 3**：.env ファイルの設定を確認します

  .env ファイルのデータベース設定と APP_KEY が揃っていることを確認してください。

  APP_KEY が未設定の場合は、次のコマンドで生成します：
  >`php artisan key:generate`

- **ステップ 4**：データベースを設定します
  
デフォルトでは、GP247 は mysql を使用します。設定は .env ファイルに次のように保存されます：
```
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=gp247
  DB_USERNAME=root
  DB_PASSWORD=
```

  手早く試すために sqlite を使いたい場合は、.env ファイルの connection を sqlite に変更し、DB_HOST、DB_PORT、DB_DATABASE、DB_USERNAME、DB_PASSWORD の行をコメントアウトしてください。
  
```
    DB_CONNECTION=sqlite
    #DB_HOST=127.0.0.1
    #DB_PORT=3306
    #DB_DATABASE=gp247
    #DB_USERNAME=root
    #DB_PASSWORD=
```
- **ステップ 5**：gp247 を初期化します

  次のコマンドを実行します：
  >`php artisan gp247:install`

  `gp247:install` は、存在する GP247 パッケージを自動で検出し、順番に
  （core [+front] [+shop]）インストールします。デフォルトでは確認を求めます。
  対話なしでインストールするには `--force=1` を追加してください。

- **ステップ 6**：エラーハンドリングを追加します

  アプリケーションに独自のエラーハンドリングを追加するには、`bootstrap/app.php` ファイルを開き、`withExceptions` 関数に次のコードを追加します：

  ```php
        // GP247 add new
        $exceptions->report(function (\Throwable $e) {
            try {
                if (function_exists('gp247_handle_exception')) {
                    gp247_handle_exception($e);
                }
            } catch (\Throwable $inner) {
                \Log::error($e->getMessage(), ['exception' => $e]);
            }
        });
  ```

  このコードにより、`gp247_handle_exception` 関数が存在する場合、その関数を通じて例外を処理できるようになります。
## 役立つ情報：

> 📖 **コマンドラインリファレンス（完全版）。** 以下は最もよく使うコマンドだけです。
> GP247 のすべての artisan コマンドとそのオプション、使用例は、公式ドキュメントを
> ご覧ください：[English](https://github.com/gp247net/gp247-docs/blob/main/system/command-line-reference.md)。

**GP247 のバージョンを確認するには**

>`php artisan gp247:info`

**gp247 のアップデート**

次のコマンドでパッケージをアップデートします：
>`composer update gp247/core`

その後、データを破壊しない安全なリフレッシュコマンドを実行します（core を更新し、shop
モジュールがあれば shop のスキーマを更新し、キャッシュを再構築します）：

>`php artisan gp247:update`

**オプション — 言語ファイルを更新する。** デフォルトでは、`gp247:update` はあなたの
翻訳をそのまま保持します。`--overwrite-lang` を追加すると最新の翻訳を取得し、
**あなたが編集した言語文字列はすべて上書きされます**：

>`php artisan gp247:update --overwrite-lang`

**オプション — publish 済みのアセット／ビューを最新版に更新する。**
`composer update` が更新するのは `vendor/` 内のパッケージだけです。`public/GP247` と
`resources/views/vendor/gp247-admin` に publish 済みのファイルは、自動では上書き
**されません**。最も簡単なのは、`gp247:update` の**任意**オプション `--publish=<tokens>`
を使う方法です（デフォルトでは何も publish しません）。安全なのは `core-public`（ビルド
済みの管理画面アセット）だけです。ビューのトークンはあなたのカスタマイズを上書きするため、
先にバックアップを取ってください：

>`php artisan gp247:update --publish=core-public` // 安全：管理画面の CSS/JS を更新

>`php artisan gp247:update --publish=core-public,core-view` // 管理画面のビューも上書き（データを破壊）

`gp247:update` には `--force` フラグは**ありません** — データを破壊するトークンを自分で
入力すること自体が同意の意思表示であり、対話実行時には引き続き警告と確認が表示されます。

または、`vendor:publish --force` でタグごとに手動で publish します：

>`php artisan vendor:publish --tag=gp247:core-public --force` // -> public/GP247 (build admin: CSS/JS, ...)

>`php artisan vendor:publish --tag=gp247:core-view --force` // -> resources/views/vendor/gp247-admin

`gp247/front をインストール済みの場合`は、front のアセット／ビューも再度 publish します：

>`php artisan vendor:publish --tag=gp247:front-public --force` // -> public/GP247/Templates/GP247Front (CSS/JS storefront)

>`php artisan vendor:publish --tag=gp247:front-view --force` // -> app/GP247/Templates/GP247Front（デフォルトテンプレートのビュー）

> ⚠️ `--force` は、出力先ディレクトリのファイルを**上書き**します。そこで行ったローカルの
> カスタマイズも含まれます。**先に `public/GP247` と publish 済みのビューディレクトリを
> バックアップし**、本当に必要なタグだけを publish してください。

**プラグインを作成するには：**

>`php artisan gp247:make-plugin  --name=PluginName`

プラグインの zip ファイルを作成するには

>`php artisan gp247:make-plugin  --name=PluginName --download=1`

**テンプレートを作成するには（`gp247/front をインストール済みの場合`）：**

>`php artisan gp247:make-template  --name=TemplateName`

テンプレートの zip ファイルを作成するには：


>`php artisan gp247:make-template  --name=TemplateName --download=1`

## カスタマイズ


**アップロード用の lfm 設定をカスタマイズする**

>`php artisan vendor:publish --tag=config-lfm`

**core 管理画面のビューをカスタマイズする**

>`php artisan vendor:publish --tag=gp247:core-view` // -> views/vendor/gp247-admin


**ヘルパー関数 gp247_* をオーバーライドする**

>ステップ 1：オーバーライドしたい関数のリストを `config/gp247_functions_except.php` に追加します

>ステップ 2：新しい関数を含む php ファイルを `app/GP247/Helpers` ディレクトリに作成します。例：`app/GP247/Helpers/myfunction.php`

**gp247 のコントローラーファイルをオーバーライドする**

>ステップ 1：オーバーライドしたいコントローラーファイルを vendor/gp247/core/src/Core/Controllers -> app/GP247/Core/Controllers にコピーします

>ステップ 2：`namespace GP247\Core\Controllers` を `namespace App\GP247\Core\Controllers` に変更します

**gp247 の API コントローラーファイルをオーバーライドする**

>ステップ 1：オーバーライドしたいコントローラーファイルを vendor/gp247/core/src/Api/Controllers -> app/GP247/Core/Api/Controllers にコピーします

>ステップ 2：`namespace GP247\Core\Api\Controllers` を `namespace App\GP247\Core\Api\Controllers` に変更します

## ルートの追加

ルート定義では、プレフィックスとミドルウェアの定数 `GP247_ADMIN_PREFIX`、`GP247_ADMIN_MIDDLEWARE` を使用してください。

参考：https://github.com/gp247net/core/blob/master/src/routes.php

## .env ファイルの環境変数

**GP247 とプラグインをすばやく無効にする**
> `GP247_ACTIVE=1` // 無効にするには値を 0 に設定

**API を無効にする**
> `GP247_API_MODE=1` // 無効にするには値を 0 に設定

**データテーブルのプレフィックス**
> `GP247_DB_PREFIX=gp247_` //gp247 のインストール後は変更できません

**管理画面へのパスのプレフィックス**
> `GP247_ADMIN_PREFIX=gp247_admin` 
