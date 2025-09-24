<?php
namespace Arshline\Core;

class FeatureFlags
{
    protected array $flags = [];

    public function __construct(array $defaults = [])
    {
        $this->flags = $defaults;
    }

    public function set(string $flag, bool $value): void
    {
        $this->flags[$flag] = $value;
    }

    public function get(string $flag): bool
    {
        return $this->flags[$flag] ?? false;
    }

    public function all(): array
    {
        return $this->flags;
    }
}
