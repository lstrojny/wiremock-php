<?php

namespace WireMock\SerdeGen;

use ReflectionClass;
use ReflectionException;
use WireMock\Serde\PropertyMap;
use WireMock\Serde\SerdeProp;
use WireMock\Serde\SerializationException;
use WireMock\Serde\Type\SerdeType;
use WireMock\Serde\Type\SerdeTypeArray;
use WireMock\Serde\Type\SerdeTypeAssocArray;
use WireMock\Serde\Type\SerdeTypeClass;
use WireMock\Serde\Type\SerdeTypeNull;
use WireMock\Serde\Type\SerdeTypePrimitive;
use WireMock\Serde\Type\SerdeTypeTypedArray;
use WireMock\Serde\Type\SerdeTypeUnion;
use WireMock\Serde\Type\SerdeTypeUntypedArray;

class SerdeTypeParser
{
    /** @var PartialSerdeTypeLookup */
    private $partialSerdeTypeLookup;
    /** @var FullyQualifiedNameGuesser */
    private $fqnGuesser;

    /**
     * @param PartialSerdeTypeLookup $partialSerdeTypeLookup
     * @param FullyQualifiedNameGuesser $fqnGuesser
     */
    public function __construct(PartialSerdeTypeLookup $partialSerdeTypeLookup, FullyQualifiedNameGuesser $fqnGuesser)
    {
        $this->partialSerdeTypeLookup = $partialSerdeTypeLookup;
        $this->fqnGuesser = $fqnGuesser;
    }

    /**
     * @throws SerializationException|ReflectionException
     */
    public function parseTypeString(string $type): SerdeType
    {
        $isNullable = false;

        // Eat nullability by union
        // TODO: null not at end of type union? regex replace?
        if (substr($type, -5) === '|null') {
            $type = substr($type, 0, -5);
            $isNullable = true;
        } else if (substr($type, -7) === ' | null') {
            $type = substr($type, 0, -7);
            $isNullable = true;
        }
        // TODO: nullability by ? (in other cases)

        if (
            $type === 'bool' || $type === 'boolean' ||
            $type === 'int' || $type === 'integer' ||
            $type === 'float' || $type === 'double' ||
            $type === 'string'
        ) {
            $serdeType = new SerdeTypePrimitive($type);
            return $isNullable ? $this->unionWithNull($serdeType) : $serdeType;
        } elseif ($type === 'array') {
            $serdeType = new SerdeTypeUntypedArray();
            return $isNullable ? $this->unionWithNull($serdeType) : $serdeType;
        } elseif ($type === 'null') {
            return new SerdeTypeNull();
        } elseif (strpos($type, '|') !== false) {
            $unionTypes = array_map(function($t) { return $this->parseTypeString($t); }, preg_split('/\|/', $type));
            $primitives = [];
            $nonPrimitive = null;
            foreach ($unionTypes as $t) {
                if ($t instanceof SerdeTypePrimitive) {
                    $primitives[] = $t;
                } elseif (($t instanceof SerdeTypeClass) || ($t instanceof SerdeTypeArray)) {
                    if ($nonPrimitive !== null) {
                        throw new SerializationException("Serde of union types with more than one non-primitive type are not supported: $type");
                    } else {
                        $nonPrimitive = $t;
                    }
                } else {
                    throw new SerializationException("Serde of union types only supported for primitives, classes, and arrays: $type");
                }
            }
            if ($isNullable) {
                $primitives[] = new SerdeTypeNull();
            }
            return new SerdeTypeUnion($primitives, $nonPrimitive);
        } elseif (substr($type, -2) === '[]') {
            $elementTypeString = substr($type, 0, -2);
            $elementType = $this->parseTypeString($elementTypeString);
            $serdeType = new SerdeTypeTypedArray($elementType);
            return $isNullable ? $this->unionWithNull($serdeType) : $serdeType;
        } else {
            $matches = array();
            if (preg_match('/array<\s*([^,]+?)\s*,\s*([^>]+?)\s*>/', $type, $matches) === 1) {
                $key = $this->parseTypeString($matches[1]);
                $value = $this->parseTypeString($matches[2]);
                if (!($key instanceof SerdeTypePrimitive)) {
                    throw new SerializationException("Unexpected key type of associative array: $type");
                }
                $serdeType = new SerdeTypeAssocArray($key, $value);
                return $isNullable ? $this->unionWithNull($serdeType) : $serdeType;
            } else {
                if (substr($type, 0, 1) === '?') {
                    $isNullable = true;
                    $type = substr($type, 1);
                }
                $fqn = $this->fqnGuesser->getFullyQualifiedName($type);
                if ($fqn === null) {
                    throw new SerializationException("Tried to parse unexpected type: $type");
                }
                if (!$this->partialSerdeTypeLookup->contains($fqn, $isNullable)) {
                    // Add a SerdeTypeClass with a placeholder property map, to break cycles
                    $placeholderPropMap = new PropertyMap([], []);
                    $serdeTypeClass = new SerdeTypeClass($fqn, $placeholderPropMap);
                    $serdeType = $isNullable ? $this->unionWithNull($serdeTypeClass) : $serdeTypeClass;
                    $this->partialSerdeTypeLookup->addSerdeType($fqn, $isNullable, $serdeType);

                    // Create the actual property map
                    $propertyMap = $this->createPropertyMap($fqn);

                    // Overwrite the placeholder property map on the existing SerdeTypeClass reference
                    // Because this property is private, we do so via reflection
                    $refProp = new \ReflectionProperty($serdeTypeClass, 'propertyMap');
                    $refProp->setAccessible(true);
                    $refProp->setValue($serdeTypeClass, $propertyMap);
                }
                return $this->partialSerdeTypeLookup->getSerdeType($fqn, $isNullable);
            }
        }
    }

    private function unionWithNull(SerdeType $serdeType): SerdeTypeUnion
    {
        if ($serdeType instanceof SerdeTypePrimitive) {
            return new SerdeTypeUnion([$serdeType, new SerdeTypeNull()], null);
        } else {
            return new SerdeTypeUnion([new SerdeTypeNull()], $serdeType);
        }
    }

    /**
     * @throws SerializationException|ReflectionException
     */
    private function createPropertyMap(string $classType): PropertyMap
    {
        $refClass = new ReflectionClass($classType);
        $properties = $this->getProperties($refClass);
        $mandatoryConstructorParams = $this->getMandatoryConstructorParams($refClass, $properties);

        foreach ($mandatoryConstructorParams as $param) {
            if (array_key_exists($param->name, $properties)) {
                unset($properties[$param->name]);
            }
        }

        return new PropertyMap($mandatoryConstructorParams, $properties);
    }

    /**
     * @param ReflectionClass $refClass
     * @return SerdeProp[] keyed by prop name
     * @throws SerializationException
     * @throws ReflectionException
     */
    private function getProperties(ReflectionClass $refClass): array
    {
        $refProps = $refClass->getProperties();
        $result = array();
        foreach ($refProps as $refProp) {
            if ($refProp->isStatic()) {
                continue;
            }
            $propName = $refProp->getName();
            $docComment = $refProp->getDocComment();
            if ($docComment === false) {
                throw new SerializationException("The property $propName on class $refClass->name has no doc comment");
            }
            $propType = $this->getTypeFromPropertyComment($docComment);
            $serdeProp = new SerdeProp($propName, $propType);
            $result[$propName] = $serdeProp;
        }
        return $result;
    }

    /**
     * @throws SerializationException
     * @throws ReflectionException
     */
    private function getTypeFromPropertyComment(string $docComment): SerdeType
    {
        $matches = array();
        if (preg_match('/@var\s+(\\\?array<[^>]+>|\S+)/', $docComment, $matches) !== 1) {
            throw new SerializationException("No @var annotation in comment:\n$docComment");
        }
        return $this->parseTypeString($matches[1]);
    }

    /**
     * @param ReflectionClass $refClass
     * @param SerdeProp[] $properties keyed by prop name
     * @return SerdeProp[] in constructor args order
     * @throws SerializationException
     * @throws ReflectionException
     */
    private function getMandatoryConstructorParams(ReflectionClass $refClass, array $properties): array
    {
        $constructor = $refClass->getConstructor();
        if ($constructor === null) {
            return array();
        }

        $refParams = $constructor->getParameters();
        if (empty($refParams)) {
            return array();
        }

        $docComment = $constructor->getDocComment();

        /** @var SerdeProp[] $result */
        $result = array();
        foreach ($refParams as $refParam) {
            if ($refParam->isDefaultValueAvailable()) {
                break;
            }
            $paramName = $refParam->getName();
            if (array_key_exists($paramName, $properties)) {
                $result[] = $properties[$paramName];
            } else {
                if ($docComment === false) {
                    throw new SerializationException(
                        "Could not find type for constructor param $paramName on type $refClass->name because it has no type"
                    );
                }
                $paramType = $this->getTypeFromParamComment($paramName, $docComment);
                $serdeProp = new SerdeProp($paramName, $paramType);
                $result[] = $serdeProp;
            }
        }
        return $result;
    }

    /**
     * @throws SerializationException
     * @throws ReflectionException
     */
    private function getTypeFromParamComment(string $paramName, string $docComment): SerdeType
    {
        $matches = array();
        if (preg_match("/@param\\s+(?:\\$$paramName\\s+(\\S+))|(?:(\\S+)\\s+\\$$paramName)/", $docComment, $matches) !== 1) {
            throw new SerializationException("No @param annotation for $paramName in comment:\n$docComment");
        }
        $type = empty($matches[1]) ? $matches[2] : $matches[1];
        return $this->parseTypeString($type);
    }
}