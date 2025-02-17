<?php
namespace MoneyroPaymentPlugin;
if (!defined('ABSPATH')) {
    exit;
}

// Load Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Load the DI container
require_once __DIR__ . '/container.php';
