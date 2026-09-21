# FLC Mobile (Flutter)

WebView shell cho FLC web app: đăng nhập Google native → handoff Sanctum → Laravel session → load UI Blade trong WebView.

## Flow

1. **Sign in with Google** (`flutter_web_auth_2` → `flc://oauth-callback`) → Sanctum token  
2. `POST /api/auth/webview-session` → one-time handoff URL  
3. WebView mở web app (UA `FLCApp/`, theme sync qua `flc_theme`)

## Cấu hình (`.env`)

```env
WEBAPP_URL=https://flc.nvnhan0810.com
API_BASE_URL=https://flc.nvnhan0810.com/api
```

Local:

```env
# Android emulator
WEBAPP_URL=http://10.0.2.2:8080
API_BASE_URL=http://10.0.2.2:8080/api

# iOS Simulator
WEBAPP_URL=http://localhost:8080
API_BASE_URL=http://localhost:8080/api
```

Copy: `cp .env.example .env`

## Chạy

```bash
cd mobile
cp .env.example .env
flutter pub get
flutter run
```

## Cấu trúc

```
lib/
  features/auth/       # Login native
  features/webapp/     # WebView shell
  core/auth/           # Google OAuth + handoff
  core/webview/        # Deep-link path mapping
  core/fcm/            # Push + in-app navigation
  router/              # /login + /app
```
