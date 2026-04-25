<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Config;
use EA\BI\Odoo\OdooFactory;

final class SettingsController
{
    public function show(): void
    {
        ea_require_login();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        ea_render('settings', [
            'config'    => Config::all(),
            'flash'     => $flash,
            'activeNav' => 'settings',
            'pageTitle' => 'Settings',
        ]);
    }

    public function save(): void
    {
        ea_require_login();

        $fields = [
            'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY',
            'ODOO_PROTOCOL', 'REVENUE_SOURCE', 'APP_BRAND',
            'AI_PROVIDER', 'AI_MODEL', 'AI_API_KEY',
        ];
        $new = [];
        foreach ($fields as $f) {
            $new[$f] = trim((string) ($_POST[$f] ?? ''));
        }
        // Keep existing secret fields if the user left them blank.
        if ($new['ODOO_API_KEY'] === '') {
            $new['ODOO_API_KEY'] = (string) Config::get('ODOO_API_KEY', '');
        }
        if ($new['AI_API_KEY'] === '') {
            $new['AI_API_KEY'] = (string) Config::get('AI_API_KEY', '');
        }
        // Preserve brand if left blank (so "save" without editing is non-destructive).
        if ($new['APP_BRAND'] === '') {
            $new['APP_BRAND'] = (string) Config::get('APP_BRAND', 'EA Business Intelligence');
        }

        $logoMsg = $this->handleLogoUpload($new);

        // Remove logo on request.
        if (!empty($_POST['remove_logo'])) {
            $current = (string) Config::get('APP_LOGO', '');
            if ($current !== '') {
                @unlink(dirname(__DIR__, 2) . '/public/uploads/' . $current);
            }
            $new['APP_LOGO'] = '';
            $logoMsg = 'Logo removed.';
        }

        $this->writeEnv($new);
        // Reload into memory
        foreach ($new as $k => $v) {
            Config::set($k, $v);
        }
        ea_cache_clear();

        $msg = 'Settings saved.' . ($logoMsg ? ' ' . $logoMsg : '');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: /settings');
        exit;
    }

    /**
     * Save an uploaded logo to /public/uploads/ and populate $new['APP_LOGO']
     * with the canonical filename. Returns a short status string for flash.
     */
    private function handleLogoUpload(array &$new): string
    {
        if (empty($_FILES['logo']['name']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            // No new upload; keep existing file name if any.
            $new['APP_LOGO'] = (string) Config::get('APP_LOGO', '');
            return '';
        }
        $err = $_FILES['logo']['error'];
        if ($err !== UPLOAD_ERR_OK) {
            return 'Logo upload failed (code ' . (int) $err . ').';
        }

        $tmp  = $_FILES['logo']['tmp_name'];
        $size = (int) ($_FILES['logo']['size'] ?? 0);
        if ($size > 2 * 1024 * 1024) {
            return 'Logo rejected (max 2 MB).';
        }
        // Sniff MIME to decide extension; never trust the client-supplied name.
        $mime = @mime_content_type($tmp) ?: '';
        $allowed = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            'image/gif'     => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            return 'Logo rejected (only PNG / JPEG / SVG / WEBP / GIF accepted).';
        }
        $ext  = $allowed[$mime];
        $dir  = dirname(__DIR__, 2) . '/public/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        // Delete any previous logo so we don't accumulate orphans.
        $prev = (string) Config::get('APP_LOGO', '');
        if ($prev !== '' && is_file($dir . '/' . $prev)) {
            @unlink($dir . '/' . $prev);
        }
        $name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
            return 'Could not save logo to disk.';
        }
        $new['APP_LOGO'] = $name;
        return 'Logo updated.';
    }

    public function testConnection(): void
    {
        ea_require_login();
        header('Content-Type: application/json');
        try {
            $client = OdooFactory::fromConfig();
            $uid = $client->authenticate();
            echo json_encode(['ok' => true, 'uid' => $uid]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function writeEnv(array $values): void
    {
        $root = dirname(__DIR__, 2);
        $file = $root . DIRECTORY_SEPARATOR . '.env';
        $existing = [];
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                if ($line === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $existing[trim($k)] = $v;
            }
        }
        foreach ($values as $k => $v) {
            $existing[$k] = $v;
        }
        $out = "# Managed by EA BI. Edit via /settings or directly.\n";
        foreach ($existing as $k => $v) {
            $out .= $k . '=' . $v . "\n";
        }
        file_put_contents($file, $out, LOCK_EX);
    }
}
