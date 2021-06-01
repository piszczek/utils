<?php declare(strict_types=1);

namespace OAS\Utils;

trait Serializable
{
    public function jsonSerialize()
    {
        return array_filter(
            get_object_vars($this),
            fn ($value, string $name) => !in_array($name, $this->excludeProperties()) && !is_null($value),
            ARRAY_FILTER_USE_BOTH
        );
    }

    protected function excludeProperties(): array
    {
        return [];
    }
}
