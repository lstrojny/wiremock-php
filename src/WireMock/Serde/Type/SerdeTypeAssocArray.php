<?php

namespace WireMock\Serde\Type;

use WireMock\Serde\ArrayMapUtils;
use WireMock\Serde\Serializer;

class SerdeTypeAssocArray extends SerdeTypeArray
{
    /** @var SerdeTypePrimitive */
    public $keyType;
    /** @var SerdeType */
    public $valueType;

    /**
     * @param SerdeTypePrimitive $keyType
     * @param SerdeType $valueType
     */
    public function __construct(SerdeTypePrimitive $keyType, SerdeType $valueType)
    {
        $this->keyType = $keyType;
        $this->valueType = $valueType;
    }

    static function setKeyValueTypes(
        SerdeTypeAssocArray $arrayType,
        SerdeTypePrimitive $keySerdeType,
        SerdeType $valueSerdeType
    ) {
        $arrayType->keyType = $keySerdeType;
        $arrayType->valueType = $valueSerdeType;
    }

    function displayName(): string
    {
        $key = $this->keyType->displayName();
        $value = $this->valueType->displayName();
        return "array<$key, $value>";
    }

    function denormalizeFromArray(array &$data, Serializer $serializer, array $path): array
    {
        return ArrayMapUtils::array_map_assoc(
            function($key, $value) use ($serializer, $path) {
                $newKeyPath = $path;
                $newKeyPath[] = "key<$key>";
                $newKey = $this->keyType->denormalize($key, $serializer, $newKeyPath);

                $newValuePath = $path;
                $newValuePath[] = "[$key]";
                $newValue = $this->valueType->denormalize($value, $serializer, $newValuePath);

                return [$newKey, $newValue];
            },
            $data
        );
    }
}