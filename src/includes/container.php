<?php
require 'vendor/autoload.php';
namespace MoneyroPaymentPlugin;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

class Container {
    private static $instance;

    public static function getContainer(): ContainerInterface {
        if (!self::$instance) {
            $containerBuilder = new ContainerBuilder();
            $containerBuilder->addDefinitions([
                IWC_Moneyro_Payment::class => DI\create(WC_Moneyro_Payment::class)->constructor(DI\get(IEngine::class))
            ]);
            self::$instance = $containerBuilder->build();
        }
        return self::$instance;
    }
}