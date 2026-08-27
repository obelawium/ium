<?php

namespace Obelaw\Ium\Bases;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

readonly abstract class BaseDto
{
    public function __construct(array $data)
    {
        $this->validate($data);
        $this->hydrate($data);
    }

    protected function validate(array $data): void
    {
        $validator = Validator::make($data, $this->rules());

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
    }

    protected function hydrate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    abstract protected function rules(): array;
}
