<?php

namespace Solarise\SmartTriage\Taxonomies;

use BackedEnum;
use UnitEnum;

class ArrayTaxonomy implements Taxonomy
{
    public function __construct(protected array $options) {}

    public static function make(array $options): self
    {
        return new self($options);
    }

    /**
     * A PHP enum is the most convenient taxonomy there is when the set is fixed
     * at deploy time. Cases become option names; a `description()` method on the
     * enum, if present, becomes the criteria.
     *
     * @param  class-string<UnitEnum>  $enum
     */
    public static function fromEnum(string $enum): self
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $name = $case instanceof BackedEnum ? (string) $case->value : $case->name;

            $options[$name] = method_exists($case, 'description') ? $case->description() : null;
        }

        return new self($options);
    }

    public function options(): array
    {
        return $this->options;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->options));
    }
}
