<?php


class DIContainer {

    private $services = [];

    // Register a service
    public function set($name, $service) {
        $this->services[$name] = $service;
    }

    // Retrieve a service
    public function get($name) {
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }

        throw new Exception("Service {$name} not found.");
    }
}

?>