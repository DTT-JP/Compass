# Compass

Compass は、ユーザーからの入力内容をもとにレポート生成や管理画面での確認を行える Web アプリです。

## 作成した動機

このアプリは、**幼馴染の女子に頼まれたから**作成しました。

## 主な内容

- フロント側の入力・表示機能（`index.php`, `compass.js`, `compass.css`）
- レポート関連処理（`compass-report.js`）
- 管理画面（`admin/index.php`, `admin/compass-admin.js`, `admin/compass-report-admin.js`）

## テスト用ページ

以下で動作確認できます。

- https://compass.dttjp.com

> **注釈**
> 無料の Gemini API を利用しているため、アクセスが多い場合は API 制限により一時的に停止する可能性があります。

## デプロイ方法

### 1. サーバー準備

- PHP が動作する Web サーバー（Apache / Nginx + PHP-FPM など）を用意
- ドキュメントルート配下へ本リポジトリを配置

### 2. ファイル配置

最低限、以下の構成で配置してください。

- `index.php`
- `env.php`
- `compass.js`
- `compass.css`
- `compass-report.js`
- `admin/` ディレクトリ配下一式

### 3. 環境変数・設定

- `env.php` に必要な API キーや接続設定を記述
- 本番運用では `env.php` を公開リポジトリに含めず、サーバー側で安全に管理してください

### 4. 公開

- Web サーバーを再読み込み（または再起動）
- ブラウザで `index.php` へアクセスして表示を確認

### 5. 動作確認

- フロント画面の入力から結果表示までを確認
- 管理画面 (`admin/index.php`) にアクセスし、必要機能を確認

---

必要に応じて、運用環境に合わせてログ管理・アクセス制限・監視設定を追加してください。
