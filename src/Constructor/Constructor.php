<?php declare(strict_types=1);

namespace OAS\Utils\Constructor;

use Laminas\Code\Reflection\ClassReflection;
use Laminas\Code\Reflection\DocBlock\Tag\ParamTag;
use Laminas\Code\Reflection\ParameterReflection;
use OAS\Utils\Constructor\Event\AfterParamsResolution;
use OAS\Utils\Constructor\Event\BeforeParamResolution;
use OAS\Utils\Constructor\Event\BeforeParamsResolution;
use OAS\Utils\Constructor\Event\BeforeParamWithTypeResolution;
use PhpParser\BuilderFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

class Constructor
{
    /**
     * map of shape
     *  class name <string> -> constructor parameters metadata <ParameterMetadata[]>
     */
    private array $parametersMetadata;
    private ?EventDispatcherInterface $dispatcher;
    private array $reflections = [];

    public function __construct(EventDispatcherInterface $dispatcher = null)
    {
        $this->dispatcher = $dispatcher;
        $this->parametersMetadata = [];
    }

    public function construct(string $type, array $params): object
    {
        return $this->build($type, $params, fn (ClassReflection $reflection, $params) => $reflection->newInstance(...$params));
    }

    public function getAST(string $type, array $params)
    {
        $factory = new BuilderFactory;

        return $this->build(
            $type,
            $params,
            fn (ClassReflection $reflection, array $params) => $factory->new($reflection->getName(), $params)
        );
    }

    public function build(string $type, array $params, callable $builder)
    {
        $reflection = $this->getReflection($type);
        $beforeResolution = $this->dispatchBeforeParamsEvent($reflection, $params);
        $params = $this->resolveConstructorParameters($reflection, $beforeResolution->getParams(), $builder);
        $afterResolution = $this->dispatchAfterParamsEvent($reflection, $params);

        return call_user_func($builder, $reflection, array_values($afterResolution->getParams()));
    }

    private function resolveConstructorParameters(ClassReflection $reflection, $parameters, callable $callback): array
    {
        $metadata = $this->getConstructorParametersMetadata($reflection);

        // parameters with at least one non-primitive type, e.g:
        //
        // /**
        //  * @param \GlobIterator|string $dir <-- \GlobIterator is non-primitive
        //  * @param bool $recursively
        //  */
        // function readDir($dir, bool $recursively) {
        //  ...
        // }
        $nonPrimitiveParameters = array_filter(
            $metadata, fn (ParameterMetadata $parameterMetadata) => $parameterMetadata->isComplex()
        );

        $resolvedParameters = array_map(
            function (ParameterMetadata $metadata) use ($parameters, $callback) {
                $errors = [];
                $name = $metadata->getName();
                $value = $this->dispatchBeforeParamEvent($metadata, $parameters[$name] ?? null)->getValue();

                if ($metadata->isNullable() && is_null($value)) {
                    return null;
                }

                // iterate over each type (in declaration order)
                // and try to resolve parameter value
                foreach ($metadata->getTypes() as [$type, $isPrimitive, $isList]) {
                    if ('null' == $type) {
                        continue;
                    }

                    if ($isPrimitive) {
                        return $this->dispatchBeforeParamWithTypeEvent($metadata, $value, $type)->getValue();
                    }

                    try {
                        if ($isList) {
                            if (!is_array($value)) {
                                throw new \RuntimeException("Type {$type}[] expects an array");
                            }

                            return array_map(
                                fn ($value) => $this->resolveConstructorParameter(
                                    $metadata,
                                    $value,
                                    $type,
                                    $callback
                                ),
                                $value
                            );
                        } else {
                            return $this->resolveConstructorParameter($metadata, $value, $type, $callback);
                        }
                    } catch (ConstructionException $instantiationError) {
                        throw new ConstructionException($name, [], $instantiationError);
                    } catch (\Throwable $e) {
                        $errors[] = $e;
                    }
                }

                throw new ConstructionException($name, $errors);
            },
            $nonPrimitiveParameters
        );

        $defaults = array_map(
            fn (ParameterMetadata $parameterMetadata) => $parameterMetadata->getDefault(null), $metadata
        );

        return array_merge($defaults, $parameters, $resolvedParameters);
    }

    private function resolveConstructorParameter(ParameterMetadata $metadata, $value, string $type, callable $callback)
    {
        $value = $this->dispatchBeforeParamWithTypeEvent($metadata, $value, $type)->getValue();

        if (is_object($value)) {
            return $value;
        }

        return $this->build($type, $value, $callback);
    }

    private function getConstructorParametersMetadata(ClassReflection $reflection): array
    {
        $className = $reflection->getName();

        if (!array_key_exists($className, $this->parametersMetadata)) {
            if ($reflection->hasMethod('__construct')) {
                $constructor = $reflection->getMethod('__construct');
                $dockBlock = $constructor->getDocBlock();
                $paramTags = array_reduce(
                    $dockBlock ? $dockBlock->getTags('param') : [],
                    function ($paramTags, ParamTag $paramTag) {
                        $paramName = $paramTag->getVariableName();

                        if (!is_null($paramName)) {
                            // name is always prefixed with "$"
                            $paramTags[substr($paramName, 1)] = $paramTag;
                        }

                        return $paramTags;
                    },
                    []
                );

                $this->parametersMetadata[$className] =  array_reduce(
                    $constructor->getParameters(),
                    function (array $parametersMetadata, ParameterReflection $reflection) use ($paramTags) {
                        $name = $reflection->getName();
                        $parametersMetadata[$name] = new ParameterMetadata(
                            $reflection, $paramTags[$name] ?? null
                        );

                        return $parametersMetadata;
                    },
                    []
                );
            } else {
                $this->parametersMetadata[$className] = [];
            }
        }

        return $this->parametersMetadata[$className];
    }

    private function dispatchBeforeParamsEvent(ClassReflection $reflection, $params): BeforeParamsResolution
    {
        return $this->conditionallyDispatch(
            new BeforeParamsResolution($reflection, $params)
        );
    }

    private function dispatchAfterParamsEvent(ClassReflection $reflection, $params): AfterParamsResolution
    {
        return $this->conditionallyDispatch(
            new AfterParamsResolution($reflection, $params)
        );
    }

    private function dispatchBeforeParamEvent(ParameterMetadata $metadata, $value): BeforeParamResolution
    {
        return $this->conditionallyDispatch(
            new BeforeParamResolution($this, $metadata, $value)
        );
    }

    private function dispatchBeforeParamWithTypeEvent(ParameterMetadata $metadata, $value, string $type): BeforeParamWithTypeResolution
    {
        return $this->conditionallyDispatch(
            new BeforeParamWithTypeResolution($this, $metadata, $value, $type)
        );
    }

    private function conditionallyDispatch($event): object
    {
        if (!is_null($this->dispatcher)) {
            $this->dispatcher->dispatch($event);
        }

        return $event;
    }

    private function getReflection(string $class): ClassReflection
    {
        return $this->reflections[$class] = $this->reflections[$class] ?? new ClassReflection($class);
    }
}
