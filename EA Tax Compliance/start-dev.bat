@echo off
REM Starts the EA Business Intelligence dev server using the bundled PHP.
REM Usage: double-click this file, or run it from PowerShell / cmd.
cd /d "%~dp0"
echo.
echo EA Business Intelligence - dev server
echo URL : http://localhost:8080
echo Ctrl+C to stop.
echo.
"%~dp0php-runtime\php.exe" -c "%~dp0php-runtime\php.ini" -S localhost:8080 -t public
