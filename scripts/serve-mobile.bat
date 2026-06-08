@echo off
setlocal

set PORT=8000
set IP=192.168.1.108
set PHP=

if not "%MOBILE_LAN_IP%"=="" set IP=%MOBILE_LAN_IP%
if not "%MOBILE_PORT%"=="" set PORT=%MOBILE_PORT%

cd /d "%~dp0.."

if defined LARAGON_PHP set PHP=%LARAGON_PHP%

if not defined PHP if exist "E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" (
    set PHP=E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
)

if not defined PHP (
    where php >nul 2>&1
    if not errorlevel 1 set PHP=php
)

if not defined PHP (
    echo ERROR: php not found. Set LARAGON_PHP or add PHP to PATH.
    exit /b 1
)

echo.
echo  Phone test URL: http://%IP%:%PORT%
echo  PHP: %PHP%
echo  Run npm run build first. Add %IP% to Yandex Maps Referer.
echo.

"%PHP%" artisan serve --host=0.0.0.0 --port=%PORT%
