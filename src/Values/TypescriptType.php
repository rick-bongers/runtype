<?php

namespace Vagebond\Runtype\Values;

use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

class TypescriptType
{
    /** @var TypescriptProperty[] */
    private array $properties = [];

    private ?string $rawType = null;

    public function __construct(
        private string $class
    ) {}

    public function addProperty(TypescriptProperty $property): self
    {
        $property = $this->dockBlock($property);

        $this->properties[] = $property;

        return $this;
    }

    public function addProperties(iterable $properties): self
    {
        foreach ($properties as $property) {
            $this->addProperty($property);
        }

        return $this;
    }

    public function listProperties(): Collection
    {
        return collect($this->properties);
    }

    public function getName(): string
    {
        return self::determineName($this->class);
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function setRawType(string $type): self
    {
        $this->rawType = $type;

        return $this;
    }

    public function getRawType(): ?string
    {
        return $this->rawType;
    }

    public function merge(TypescriptType $type, Collection $originalProperties): self
    {
        $newProperties = $type->listProperties()->filter(fn ($prop) => ! $this->listProperties()->contains($prop));

        $this->addProperties($newProperties);

        $optionalProperties = $this->listProperties()
            ->filter(fn ($prop) => ! $originalProperties->contains($prop));

        $optionalProperties->each(fn ($prop) => $prop->setOptional(true));

        $missingProperties = $originalProperties->where(fn ($prop) => !$type->listProperties()->contains($prop));
        $missingProperties->each(fn ($prop) => $prop->setOptional(true));

        return $this;
    }

    public static function determineNamespace(string $className): string
    {
        $namespace = explode('\\', $className);
        array_pop($namespace);

        if (empty($namespace)) {
            return '';
        }

        return implode('.', $namespace);
    }

    public static function unwrapData(string $data): string
    {
        $constructor = (new ReflectionClass($data))->getConstructor();

        if ($constructor === null) {
            return '{}';
        }

        $properties = collect($constructor->getParameters())
            ->map(fn (ReflectionParameter $parameter): string => self::unwrapDataProperty($parameter))
            ->implode('; ');

        return "{ {$properties} }";
    }

    private static function unwrapDataProperty(ReflectionParameter $parameter): string
    {
        [$optionals, $types] = collect(explode('|', self::reflectionTypeToString($parameter->getType())))
            ->partition(fn ($item) => strtolower(trim($item)) === 'optional');

        $tsType = $types
            ->map(fn ($value) => self::phpTypeToTypescript(trim(trim($value), '\\')))
            ->filter()
            ->join(' | ');

        $optional = $optionals->isNotEmpty() || $parameter->isDefaultValueAvailable();

        return $parameter->getName() . ($optional ? '?' : '') . ': ' . ($tsType !== '' ? $tsType : 'any');
    }

    private static function reflectionTypeToString(?ReflectionType $type): string
    {
        if ($type instanceof ReflectionUnionType) {
            return collect($type->getTypes())
                ->map(fn (ReflectionNamedType $namedType) => $namedType->getName())
                ->implode('|');
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if ($type->allowsNull() && ! in_array(strtolower($name), ['null', 'mixed'], true)) {
                $name .= '|null';
            }

            return $name;
        }

        return 'mixed';
    }

    public static function determineName(string $className): string
    {
        $class = new ReflectionClass($className);

        return $class->getShortName().'Type';
    }

    public function dockBlock(TypescriptProperty $property): TypescriptProperty
    {
        $class = new ReflectionClass($this->class);
        $fileName = $class->getFileName();

        if ($fileName === false) {
            return $property;
        }

        $lines = file($fileName);

        if ($lines === false) {
            return $property;
        }

        $propertyName = $property->getRawName();

        foreach ($lines as $lineNumber => $line) {
            if (preg_match("/['\"]" . preg_quote($propertyName, '/') . "['\"]\s*=>/", $line)) {
                if ($lineNumber > 0) {
                    $previousLine = trim($lines[$lineNumber - 1]);

                    if (preg_match('/@var\s+(.+?)(?:\s*\*\/\s*$)/', $previousLine, $matches)) {
                        $varType = trim($matches[1]);

                        // Remove the variable name if present (e.g., "$canceledAt Carbon | null" -> "Carbon | null")
                        $varType = trim(preg_replace('/\s*\$\w+/', '', $varType));

                        [$optionals, $types] = collect(explode('|', $varType))->partition(fn ($item) => strtolower(trim($item)) === 'optional');

                        $types = $types->map(function ($value) use ($propertyName) {
                            $value = trim(trim($value), '\\');

                            if ($propertyName === 'linking') {
//                                if ($value)
//                                dd($value);
                            }

                            return self::phpTypeToTypescript($value);
                        });

                        $property->setType($types->join(' | '));

                        if ($optionals->isNotEmpty()) {
                            $property->setOptional(true);
                        }
                    }
                }

                break;
            }
        }

        return $property;
    }

    private static function phpTypeToTypescript(string $phpType): string
    {
        $phpType = trim($phpType);

        // Split union types (e.g., "Carbon | null")
        $parts = array_map('trim', preg_split('/\s*\|\s*/', $phpType));

        $tsParts = array_map(function (string $part): string {
            return match (true) {
                strtolower($part) === 'null' => 'null',
                strtolower($part) === 'string' => 'string',
                strtolower($part) === 'int', strtolower($part) === 'integer', strtolower($part) === 'float', strtolower($part) === 'double' => 'number',
                strtolower($part) === 'bool', strtolower($part) === 'boolean' => 'boolean',
                strtolower($part) === 'array' => 'any[]',
                strtolower($part) === 'mixed' => 'any',
                strtolower($part) === 'void' => 'void',
                // Carbon, DateTime, DateTimeInterface, etc. -> string
                str_contains(strtolower($part), 'carbon'),
                str_contains(strtolower($part), 'datetime') => 'string',
                // Class references that are Resources -> qualified type name
                class_exists($part) && is_subclass_of($part, \Illuminate\Http\Resources\Json\JsonResource::class) => self::determineNamespace($part) !== '' ? self::determineNamespace($part).'.'.self::determineName($part) : self::determineName($part),
                class_exists($part) && is_subclass_of($part, \Spatie\LaravelData\Data::class) => self::unwrapData($part),
                default => 'any',
            };
        }, $parts);

        return implode(' | ', $tsParts);
    }
}
