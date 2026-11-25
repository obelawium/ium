<?php

declare(strict_types=1);

namespace Obelaw\Ium\Bases;

use BackedEnum;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Base immutable Data Transfer Object with Laravel-backed validation.
 *
 * Supports both:
 * 1. Array-hydrated readonly subclasses (`new MyDto(['key' => 'val'])`).
 * 2. Promoted-constructor readonly subclasses calling `$this->validateSelf()`
 *    or constructed via `MyDto::from(['key' => 'val'])`.
 */
abstract readonly class BaseDto
{
    /**
     * Create a new DTO instance from a raw associative payload.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException When validation fails.
     */
    public function __construct(array $data = [])
    {
        $this->validate($data);
        $this->hydrate($data);
    }

    /**
     * Construct a validated DTO instance from an associative array.
     *
     * Automatically supports both subclasses using the default array constructor
     * and subclasses defining explicit promoted constructor parameters.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException When validation fails.
     */
    public static function from(array $data): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() === self::class) {
            /** @phpstan-ignore new.static */
            return new static($data);
        }

        $parameters = $constructor->getParameters();

        if (count($parameters) === 1) {
            $firstType = $parameters[0]->getType();

            if ($firstType instanceof ReflectionNamedType && $firstType->getName() === 'array') {
                /** @phpstan-ignore new.static */
                return new static($data);
            }
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->validate($data);

        $arguments = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $data)) {
                $arguments[$name] = self::coerceForType($parameter->getType(), $data[$name]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[$name] = null;
            }
        }

        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    /**
     * Validate the current DTO's initialized property values against `rules()`.
     *
     * Useful inside custom promoted constructors of subclasses.
     *
     * @throws ValidationException When validation fails.
     */
    protected function validateSelf(): void
    {
        $this->validate($this->toValidationArray());
    }

    /**
     * Validate a raw payload against the DTO's rules.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException When validation fails.
     */
    protected function validate(array $data): void
    {
        $rules = $this->rules();

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($data, $rules, $this->messages(), $this->attributes());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Hydrate matching properties on the DTO, respecting PHP 8.2+ readonly
     * declaring-class visibility rules.
     *
     * @param array<string, mixed> $data
     */
    protected function hydrate(array $data): void
    {
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            if (! array_key_exists($name, $data) || $property->isInitialized($this)) {
                continue;
            }

            $value = self::coerceForType($property->getType(), $data[$name]);
            $declaringClass = $property->getDeclaringClass()->getName();

            $initializer = Closure::bind(
                static function (object $target, string $prop, mixed $val): void {
                    $target->{$prop} = $val;
                },
                null,
                $declaringClass
            );

            $initializer($this, $name, $value);
        }
    }

    /**
     * Return a new validated DTO instance with selected fields overridden.
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): static
    {
        return static::from(array_merge($this->toArray(), $overrides));
    }

    /**
     * Export only the specified public properties as an associative array.
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->toArray(), array_flip($keys));
    }

    /**
     * Export all public properties except the specified keys.
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    /**
     * Export the DTO properties as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflection = new ReflectionClass($this);
        $values = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($this)) {
                continue;
            }

            $values[$property->getName()] = $property->getValue($this);
        }

        return $values;
    }

    /**
     * Export properties normalized for Laravel's validator (unwrapping BackedEnums).
     *
     * @return array<string, mixed>
     */
    protected function toValidationArray(): array
    {
        $values = [];

        foreach ($this->toArray() as $key => $value) {
            $values[$key] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $values;
    }

    /**
     * Validation rules for the DTO payload.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * Optional custom validation messages.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Optional custom validation attribute names.
     *
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [];
    }

    /**
     * Coerce a raw value to match a declared BackedEnum type when applicable.
     */
    private static function coerceForType(?\ReflectionType $type, mixed $value): mixed
    {
        if ($value === null || ! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $typeName = $type->getName();

        if (is_subclass_of($typeName, BackedEnum::class) && ! $value instanceof $typeName) {
            if (is_int($value) || is_string($value)) {
                return $typeName::from($value);
            }
        }

        return $value;
    }
}
