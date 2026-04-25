<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Config;

final class AuthController
{
    public function showLogin(): void
    {
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        ea_render('login', ['error' => $error]);
    }

    public function doLogin(): void
    {
        $u = (string) ($_POST['username'] ?? '');
        $p = (string) ($_POST['password'] ?? '');
        $expectedUser = (string) Config::get('APP_USER', 'admin');
        $expectedPass = (string) Config::get('APP_PASSWORD', 'changeme');

        if (hash_equals($expectedUser, $u) && hash_equals($expectedPass, $p)) {
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            header('Location: /');
            exit;
        }
        $_SESSION['flash_error'] = 'Invalid credentials.';
        header('Location: /login');
        exit;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        header('Location: /login');
        exit;
    }
}
