# Convenience wrapper: runs Laravel artisan through the portable PHP runtime.
# Usage: .\art.ps1 <artisan-args...>
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$php        = Join-Path $scriptRoot 'php-runtime\php.exe'
$ini        = Join-Path $scriptRoot 'php-runtime\php.ini'
$artisan    = Join-Path $scriptRoot 'laravel-app\artisan'
& $php -c $ini $artisan @args
