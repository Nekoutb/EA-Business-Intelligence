# Convenience wrapper: runs composer.phar via the portable PHP runtime.
# Usage: .\composer.ps1 <composer-args...>
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$php = Join-Path $scriptRoot 'php-runtime\php.exe'
$ini = Join-Path $scriptRoot 'php-runtime\php.ini'
$phar = Join-Path $scriptRoot 'composer.phar'
& $php -c $ini $phar @args
