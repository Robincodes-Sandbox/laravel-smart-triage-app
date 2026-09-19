<?php

namespace Solarise\SmartTriage\Taxonomies;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A taxonomy backed by a table: Category, Team, Tag, whatever the app already has.
 *
 * Note what this does NOT do — it does not send ids. The model judges words, so the
 * option name is the human label and code maps it back to a row afterwards.
 */
class EloquentTaxonomy implements Taxonomy
{
    protected ?Closure $query = null;

    /** Options are read several times per question; the table is not. */
    protected $cached = null;

    public function __construct(
        /** @var class-string<Model> */
        protected string $model,
        protected string $labelColumn = 'name',
        protected ?string $descriptionColumn = 'description',
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function make(string $model, string $labelColumn = 'name', ?string $descriptionColumn = 'description'): self
    {
        return new self($model, $labelColumn, $descriptionColumn);
    }

    /** Scope the rows that count as options — active only, one parent's children, and so on. */
    public function where(Closure $query): self
    {
        $this->query = $query;

        return $this;
    }

    public function options(): array
    {
        $options = [];

        foreach ($this->rows() as $row) {
            $label = (string) $row->getAttribute($this->labelColumn);

            $options[$label] = $this->descriptionColumn
                ? $row->getAttribute($this->descriptionColumn)
                : null;
        }

        return $options;
    }

    /** Map a chosen label back to the row it came from. */
    public function resolve(string $label): ?Model
    {
        return $this->rows()->first(
            fn (Model $row) => (string) $row->getAttribute($this->labelColumn) === $label
        );
    }

    protected function rows()
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        /** @var Builder $builder */
        $builder = $this->model::query();

        if ($this->query) {
            ($this->query)($builder);
        }

        return $this->cached = $builder->get();
    }

    /** Drop the memo after the taxonomy table changes. */
    public function refresh(): self
    {
        $this->cached = null;

        return $this;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->options()));
    }
}
