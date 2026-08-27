<?php

namespace Obelaw\Ium\Bases;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Base data transfer object with Laravel-backed validation.
 *
 * Validates the incoming payload against the DTO's rules, then hydrates
 * matching public properties. Constructed directly with an array payload
 */
readonly abstract class BaseDto
{
    /**
     * Create a new DTO instance from a raw payload.
     *
     * @param array<string, mixed> $data
     *
     * @throws \Exception When validation fails.
     */
    public function __construct(array $data)
    {
        $this->validate($data);
        $this->hydrate($data);
    }

    /**
     * Validate the payload against the DTO rules.
     *
     * @param array<string, mixed> $data
     *
     * @throws \Exception When validation fails.
     */
    protected function validate(array $data): void
    {
        $validator = Validator::make($data, $this->rules());

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
    }

    /**
     * Copy payload values onto matching DTO properties.
     *
     * @param array<string, mixed> $data
     */
    protected function hydrate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Export the DTO properties as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Validation rules for the DTO payload.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;
}
