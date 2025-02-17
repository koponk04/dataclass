<?php

declare(strict_types=1);

namespace Rutek\Dataclass;

use ReflectionClass;
use ReflectionException;
use ReflectionType;
use ReflectionNamedType;
use Rutek\Dataclass\UnsupportedException\ConstructorParamAndPropertyTypeMismatchException;
use Rutek\Dataclass\UnsupportedException\MissingPropertyForConstructorParameterException;

class Transform
{
    /**
     * Transform raw array to structure described by type-hints from given class
     *
     * @template T of object
     * @param class-string<T> $class Class to be filled in with data
     * @param mixed $data Data to map
     * @param string|null $fieldName Internal use only
     * @return T
     * @throws TransformException
     * @throws UnsupportedException
     */
    public function to(string $class, $data, ?string $fieldName = null)
    {
        if ($data === null) {
            throw new TransformException(
                $this->to(FieldError::class, ['field' => $fieldName ?? 'root', 'reason' => 'Data could not be decoded'])
            );
        }
        if (!is_array($data)) {
            throw new TransformException(
                $this->to(FieldError::class, [
                    'field' => $fieldName ?? 'root',
                    'reason' => 'Field value must be an array'
                ])
            );
        }

        $errors = [];

        $objReflection = new ReflectionClass($class);

        // Direct creation of Collections
        if ($objReflection->isSubclassOf(Collection::class)) {
            /** @var ReflectionClass<T of Collection> $objReflection */
            try {
                /** @var T */
                return $this->checkCollection($objReflection, $data, $fieldName ?? 'root');
            } catch (FieldError $e) {
                throw new TransformException($e);
            }
        }

        $properties = $objReflection->getProperties();
        $defaults = $objReflection->getDefaultProperties();

        $finalData = [];
        foreach ($properties as $property) {
            if (! $property->isPublic()) {
                continue;
            }
            $name = $property->getName();

            // We ignore untyped properties but pass data "as they are"
            $propertyType = $property->getType();
            if ($propertyType === null) {
                $finalData[$name] = $data[$name] ?? null;
                continue;
            }
            if (! ($propertyType instanceof ReflectionNamedType)) {
                // TODO: Add support for intersections and unions
                throw new UnsupportedException(
                    'Param ' . $name . ' does not appear to have simple typing, unions and intersections '
                    . 'are not supported'
                );
            }

            if (!array_key_exists($name, $data)) {
                if (!array_key_exists($name, $defaults)) {
                    // Declared property without default value does not exist in data
                    $errors[] = $this->to(FieldError::class, [
                        'field' => ($fieldName !== null ? $fieldName . '.' : '') . $name,
                        'reason' => 'Field must have value'
                    ]);
                } else {
                    // Declared property does not exists in data but has default, use it
                    $finalData[$name] = $defaults[$name];
                }
                continue;
            }

            try {
                $finalData[$name] = $this->checkType(
                    $propertyType,
                    $data[$name],
                    ($fieldName !== null ? $fieldName . '.' : '') . $name
                );
            } catch (FieldError $e) {
                $errors[] = $e;
            } catch (TransformException $e) {
                $errors = [... $errors, ...$e->getErrors()];
            }

            $propertyDoc = strval($property->getDocComment());
            if (false !== strpos($propertyDoc, 'processedProperty')) {
                unset($finalData[$name]);
            }
        }

        if (count($errors) > 0) {
            throw new TransformException(...$errors);
        }

        // We support objects that require filling in required object properties through constructor
        $constructor = $objReflection->getConstructor();
        if ($constructor !== null) {
            $constructorParams = $constructor->getParameters();
            if (count($constructorParams) > 0) {
                $constructorArgs = [];
                foreach ($constructorParams as $param) {
                    $paramName = $param->getName();
                    // If there is no data for constructor parameter, it means that there is no public property
                    // that uses the same name. It's unsupported.
                    if (!array_key_exists($paramName, $finalData)) {
                        throw new MissingPropertyForConstructorParameterException($paramName);
                    }
                    // Find property using the same name
                    try {
                        $property = $objReflection->getProperty($paramName);
                    } catch (ReflectionException $e) {
                        // Property does not exist, it's unsupported
                        throw new MissingPropertyForConstructorParameterException($paramName);
                    }
                    // Check if types between property and constructor parameter match
                    $propertyType = $property->getType();
                    $paramType = $param->getType();
                    // Check if both types are not defined or both are defined
                    if (
                        ($propertyType !== null && $paramType === null)
                        || ($propertyType === null && $paramType !== null)
                    ) {
                        throw new ConstructorParamAndPropertyTypeMismatchException($paramName);
                    }
                    // Check if types match (if both are defined)
                    if (
                        $propertyType instanceof ReflectionNamedType
                        && $paramType instanceof ReflectionNamedType
                        && $propertyType->getName() !== $paramType->getName()
                    ) {
                        throw new ConstructorParamAndPropertyTypeMismatchException($paramName);
                    }
                    // TODO: Add support for intersections and unions (they are not a ReflectionNamedType)
                    $constructorArgs[] = $finalData[$paramName];
                }
                $obj = $objReflection->newInstanceArgs($constructorArgs);
            } else {
                $obj = $objReflection->newInstance();
            }
        } else {
            $obj = new $class();
        }

        foreach ($finalData as $field => $value) {
            $obj->$field = $value;
        }

        return $obj;
    }

    /**
     * Check if scalar type is valid and return it's value
     *
     * @param string $type
     * @param mixed $data
     * @param string $fieldName
     * @return int|string|float|bool
     */
    private function checkScalar(string $type, $data, string $fieldName)
    {
        switch ($type) {
            case 'int':
                // Integer validation
                if (!is_int($data)) {
                    throw $this->to(FieldError::class, ['field' => $fieldName, 'reason' => 'Field must be integer']);
                }
                break;
            case 'string':
                // String validation
                if (!is_string($data)) {
                    throw $this->to(FieldError::class, ['field' => $fieldName, 'reason' => 'Field must be string']);
                }
                break;
            case 'float':
                // Float validation
                if (!is_float($data)) {
                    throw $this->to(FieldError::class, ['field' => $fieldName, 'reason' => 'Field must be float']);
                }
                break;
            case 'bool':
                // boolean validation
                if (!is_bool($data)) {
                    throw $this->to(FieldError::class, ['field' => $fieldName, 'reason' => 'Field must be boolean']);
                }
                break;
            default:
                throw new UnsupportedException('Unsupported built-in type: ' . $type);
        }
        return $data;
    }

    /**
     * Check if given type described by ReflectionNamedType is valid and return modified value
     * for recursive calls support
     *
     * @param ReflectionNamedType $type
     * @param mixed $data
     * @param string $fieldName
     * @return mixed
     */
    private function checkType(ReflectionNamedType $type, $data, string $fieldName)
    {
        if (! $type->allowsNull() && $data === null) {
            // Field cannot have null
            throw $this->to(FieldError::class, ['field' => $fieldName, 'reason' => 'Field cannot have null value']);
        } elseif ($type->allowsNull() && $data === null) {
            // Field contains null, it's valid
            return $data;
        }

        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            $data = $this->checkScalar($typeName, $data, $fieldName);
        } else {
            // Structured data validation
            if (class_exists($typeName)) {
                $class = new ReflectionClass($typeName);
                if ($class->isSubclassOf(Collection::class)) {
                    $data = $this->checkCollection($class, $data, $fieldName);
                } else {
                    $data = $this->to($typeName, $data, $fieldName);
                }
            } else {
                throw new UnsupportedException('Unsupported nested type ' . $typeName);
            }
        }

        return $data;
    }

    /**
     * @template T of Collection
     * @param ReflectionClass<T> $class
     * @param mixed $data
     * @param string $fieldName
     * @return T
     * @throws UnsupportedException
     * @throws FieldError
     */
    protected function checkCollection(ReflectionClass $class, $data, string $fieldName)
    {
        if (!is_array($data)) {
            throw $this->to(FieldError::class, ['field' => $fieldName, 'reason' => 'Field must be array']);
        }

        $typeName = $class->getName();

        // Collections have type hinted constructor parameter like "string ...$items"
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            throw new UnsupportedException('Collection class does not have constructor');
        }
        $constructorParams = $constructor->getParameters();
        if (count($constructorParams) !== 1) {
            throw new UnsupportedException('Collection with more than 1 argument is not supported');
        }

        // Check types recursive
        $itemType = $constructorParams[0]->getType();
        if ($itemType === null) {
            throw new UnsupportedException('Collection constructor param does not have type hint');
        }
        if (! ($itemType instanceof ReflectionNamedType)) {
            // TODO: Add support for intersections and unions
            throw new UnsupportedException(
                'Collection constructor param does not appear to have simple typing, '
                . 'unions and intersections are not supported'
            );
        }
        $objects = [];
        foreach ($data as $key => $item) {
            $objects[] = $this->checkType($itemType, $item, $fieldName . '.' . $key);
        }

        $propertyDoc = strval($class->getDocComment());
        if (false !== strpos($propertyDoc, 'processedProperty')) {
            return new $typeName();
        }

        return new $typeName(...$objects);
    }
}
