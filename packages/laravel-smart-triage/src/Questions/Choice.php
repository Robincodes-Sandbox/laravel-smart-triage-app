<?php

namespace Solarise\SmartTriage\Questions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Solarise\SmartTriage\Taxonomies\ArrayTaxonomy;
use Solarise\SmartTriage\Taxonomies\EloquentTaxonomy;
use Solarise\SmartTriage\Taxonomies\Taxonomy;

/**
 * One of a known set.
 *
 * Read the distribution, not just the winner: `probabilities` compares the
 * options against each other, and that comparison is usually the useful output.
 */
class Choice implements Question
{
    protected ?Taxonomy $taxonomy = null;

    protected ?string $escapeHatch = null;

    public function __construct(protected string $instructions) {}

    public static function make(string $instructions): self
    {
        return new self($instructions);
    }

    /**
     * Options may be a plain map, a PHP enum, an Eloquent model class, or any
     * Taxonomy. This is the "pass it a taxonomy or another model" bit.
     *
     * @param  array|class-string|Taxonomy  $options
     */
    public function among(array|string|Taxonomy $options): self
    {
        $this->taxonomy = match (true) {
            $options instanceof Taxonomy => $options,
            is_array($options) => ArrayTaxonomy::make($options),
            enum_exists($options) => ArrayTaxonomy::fromEnum($options),
            is_subclass_of($options, Model::class) => EloquentTaxonomy::make($options),
            default => throw new InvalidArgumentException(
                "Cannot build a taxonomy from [{$options}]: expected an array, an enum, an Eloquent model, or a Taxonomy."
            ),
        };

        return $this;
    }

    /**
     * Name the none-of-these option.
     *
     * Without one, a choice has no way to say "none of your options fit" and
     * will return the least bad instead — quietly, and with a confident-looking
     * distribution. Name the escape hatch whenever the input might not belong.
     */
    public function noneOf(string $option, ?string $description = null): self
    {
        $this->escapeHatch = $option;

        if ($description !== null) {
            $this->taxonomy = ArrayTaxonomy::make(
                array_merge($this->options(), [$option => $description])
            );
        }

        return $this;
    }

    public function escapeHatch(): ?string
    {
        return $this->escapeHatch;
    }

    public function taxonomy(): ?Taxonomy
    {
        return $this->taxonomy;
    }

    public function options(): array
    {
        return $this->taxonomy?->options() ?? [];
    }

    public function toPayload(): array
    {
        $options = $this->options();

        if (count($options) < 2) {
            throw new InvalidArgumentException(
                "Choice [{$this->instructions}] needs at least two options; got ".count($options).'.'
            );
        }

        if (count($options) > 255) {
            throw new InvalidArgumentException(
                'Choice supports at most 255 options; got '.count($options).'.'
            );
        }

        return [
            'type' => 'choice',
            'instructions' => $this->instructions,
            'criteria' => $options,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toPayload()));
    }
}
