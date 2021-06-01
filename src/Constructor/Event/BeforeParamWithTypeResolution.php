<?php declare(strict_types=1);

namespace OAS\Utils\Constructor\Event;

use OAS\Utils\Constructor\Constructor;
use OAS\Utils\Constructor\ParameterMetadata;

class BeforeParamWithTypeResolution extends BeforeParamResolution
{
    private string $type;

    public function __construct(Constructor $constructor, ParameterMetadata $reflection, $value, string $type)
    {
        $this->type = $type;
        parent::__construct($constructor, $reflection, $value);

    }

    public function getType(): string
    {
        return $this->type;
    }
}
