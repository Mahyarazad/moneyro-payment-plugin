<?php

use DI\Container;

$container = new Container();

// Register services
$container->set(WC_Moneyro_National_ID::class, function () {
    return new WC_Moneyro_National_ID();
});

$container->set(WC_Moneyro_UID::class, function () {
    return new WC_Moneyro_UID();
});

$container->set(WC_Moneyro_Payment::class, function () {
    return new WC_Moneyro_Payment();
});

return $container;