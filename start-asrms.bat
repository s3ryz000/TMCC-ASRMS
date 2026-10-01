@echo off
rem Double-click to start ASRMS: backend (Laravel) on :8000 and frontend (React) on :3000.
rem Requires PHP 8.2+, Composer dependencies and npm dependencies already installed (see README / setup).
title ASRMS launcher
set "ROOT=%~dp0"

rem Prefer PHP on PATH, fall back to the default XAMPP location
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"
where npm >nul 2>nul || (
  echo npm was not found. Install Node.js LTS, then reopen this window.
  pause
  exit /b 1
)

start "ASRMS backend :8000" /D "%ROOT%backend" cmd /k ""%PHP%" artisan serve --host=127.0.0.1 --port=8000"
start "ASRMS frontend :3000" /D "%ROOT%frontend" cmd /k "npm start"

echo ASRMS is starting in two windows.
echo The browser opens at http://localhost:3000 once the frontend finishes compiling.
echo To stop: close the two "ASRMS" windows.
timeout /t 8 >nul
