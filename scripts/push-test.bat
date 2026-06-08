@echo off
setlocal

set SLOT=22_00
set PHP=

cd /d "%~dp0.."

if not "%~1"=="" set SLOT=%~1

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
echo  Push test: notifications:qr-reminder %SLOT%
echo  PHP: %PHP%
echo  Before send: site open in browser, notifications enabled.
echo.

"%PHP%" artisan notifications:qr-reminder %SLOT%
exit /b %ERRORLEVEL%
