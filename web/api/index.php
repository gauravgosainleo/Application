<?php
/*
 * G VI Fan Hub - JSON API front controller.
 * Every call is  api/index.php?r=<module>/<action>  (no URL rewriting needed).
 * Each module file in app/api/ returns: action => [HTTP method, access, handler function]
 * access: public | user | admin
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$GLOBALS['__gvi_deferred'] = [];
$route = isset($_GET['r']) ? (string)$_GET['r'] : '';

try {
    if (!is_file(STORAGE_DIR . '/installed.lock')) {
        fail('The app is not installed yet. Open install.php to finish the setup.', 503, 'not_installed');
    }
    if (!preg_match('#^([a-z]+)/([a-z0-9\-]+)$#', $route, $m)) {
        fail('Unknown API route.', 404, 'not_found');
    }
    $file = APP_DIR . '/api/' . $m[1] . '.php';
    if (!is_file($file)) {
        fail('Unknown API route.', 404, 'not_found');
    }
    $routes = require $file;
    if (!isset($routes[$m[2]])) {
        fail('Unknown API route.', 404, 'not_found');
    }
    list($method, $access, $handler) = $routes[$m[2]];
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if ($requestMethod !== $method) {
        fail('Method not allowed.', 405, 'method_not_allowed');
    }

    if ($access === 'user') {
        current_user();
        if ($method === 'POST') {
            verify_csrf('user');
        }
    } elseif ($access === 'admin') {
        current_admin();
        if ($method === 'POST') {
            verify_csrf('admin');
        }
    } elseif ($method === 'POST') {
        verify_ajax_header();
    }

    $data = $handler();
    send_json(['ok' => true, 'data' => $data]);
} catch (ApiError $e) {
    $payload = ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode];
    if ($e->extra) {
        $payload = array_merge($payload, $e->extra);
    }
    send_json($payload, $e->status);
} catch (Throwable $e) {
    log_error(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . ' [' . $route . ']');
    $message = cfg('app.debug')
        ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'Something went wrong on the server. Please try again.';
    send_json(['ok' => false, 'error' => $message, 'code' => 'server_error'], 500);
}

run_deferred_jobs();
