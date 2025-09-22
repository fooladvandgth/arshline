<?php
namespace Arshline\Core;

class ServiceContainer
{
    protected array $bindings = [];
    protected array $instances = [];

    public function bind(string $name, callable $resolver): void
    {
        $this->bindings[$name] = $resolver;
    }

    public function singleton(string $name, callable $resolver): void
    {
        $this->bindings[$name] = $resolver;
        $this->instances[$name] = null;
    }

    public function make(string $name)
    {
        if (array_key_exists($name, $this->instances) && $this->instances[$name] !== null) {
            return $this->instances[$name];
        }
        if (!isset($this->bindings[$name])) {
            throw new \Exception("Service '$name' not bound.");
        }
        $object = call_user_func($this->bindings[$name], $this);
        if (array_key_exists($name, $this->instances)) {
            $this->instances[$name] = $object;
        }
        return $object;
    }
}
