<?php
/*
 * Admin API - routes are split over app/api/admin/*.php
 */

$routes = [];
foreach (['core', 'users', 'news', 'content', 'catalogue', 'engage'] as $part) {
    $routes = array_merge($routes, require __DIR__ . '/admin/' . $part . '.php');
}
return $routes;
