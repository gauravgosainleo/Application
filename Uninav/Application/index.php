<?php
require_once __DIR__ . '/includes/helpers.php';
if (is_logged_in()) redirect('dashboard.php');
redirect('auth/login.php');
