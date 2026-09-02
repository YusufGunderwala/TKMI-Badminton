<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
logoutAdmin();
header('Location: ' . BASE_URL . '/admin/login.php');
exit;
