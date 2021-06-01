<?php declare(strict_types=1);

namespace OAS\Utils;

use Biera\ArrayAccessor;
use function Biera\{pathSegments, array_key_exists};
use function OAS\Resolver\jsonPointerDecode;

class Node implements \ArrayAccess
{
    use ArrayAccessor;

    private ?Node $__parent = null;
    private array $__parentPath = [];

    public function find(string $path)
    {
        $prefix = $path[0] ?? '';

        if ('#' == $prefix || '/' == $prefix) {
            $path = substr($path, 1);
            $currentNode = $this->getRoot();
        } else {
            $currentNode = $this;
        }

        $pathSegments = pathSegments($path);

        while (!empty($pathSegments)) {
            $pathSegment = $this->decode(
                array_shift($pathSegments)
            );

            switch ($pathSegment) {
                case '.':
                    break;

                case '..':
                    if ($currentNode->isRoot()) {
                        throw new \RuntimeException($path);
                    }

                    $currentNode = $currentNode->__parent;
                    break;

                default:
                    if (!array_key_exists($pathSegment, $currentNode))  {
                        throw new \RuntimeException($path);
                    }

                    $currentNode = $currentNode[$pathSegment];
                    break;
            }
        }

        return $currentNode;
    }

    protected function __connect(Node $node, array $parentPath = []): void
    {
        $node->__parent = $this;
        $node->__parentPath = $parentPath;
    }

    private function getRoot(): Node
    {
        $current = $this;

        while (!is_null($current->__parent)) {
            $current = $current->__parent;
        }

        return $current;
    }

    public function getRootPath(): array
    {
        $path = [];
        $node = $this;

        while (!$node->isRoot()) {
            array_unshift($path, ...$node->__parentPath);
            $node = $node->__parent;
        }

        return $path;
    }

    private function isRoot(): bool
    {
        return is_null($this->__parent);
    }

    private function decode(string $pathSegment): string
    {
        return urldecode(
            jsonPointerDecode($pathSegment)
        );
    }
}

