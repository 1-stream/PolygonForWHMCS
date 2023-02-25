<?php

if (!defined('Polygon_PAY_ROOT')) {
    define('Polygon_PAY_ROOT', __DIR__);
}

if (!defined('WHMCS_ROOT')) {
    define('WHMCS_ROOT', dirname(__DIR__, 3));
}

spl_autoload_register(function ($className) {
    if (stripos($className, 'PolygonForWHMCS') !== false) {
        require __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', '/', mb_strcut($className, 16)) . '.php';
    }
});