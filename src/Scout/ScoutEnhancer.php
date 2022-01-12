<?php

namespace Nuwave\Lighthouse\Scout;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;
use Nuwave\Lighthouse\Execution\Arguments\Argument;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSet;
use Nuwave\Lighthouse\Support\Contracts\ArgBuilderDirective;
use Nuwave\Lighthouse\Support\Utils;

class ScoutEnhancer
{
    /**
     * @var \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet
     */
    protected $argumentSet;

    /**
     * @var \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    protected $builder;

    /**
     * Provide the actual search value.
     *
     * @var array<Argument>
     */
    protected $searchArguments = [];

    /**
     * Should not be there when @search is used.
     *
     * @var array<Argument>
     */
    protected $argumentsWithOnlyArgBuilders = [];

    /**
     * Can enhance the scout builders.
     *
     * @var array<Argument>
     */
    protected $argumentsWithScoutBuilderDirectives = [];

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $builder
     */
    public function __construct(ArgumentSet $argumentSet, object $builder)
    {
        $this->argumentSet = $argumentSet;
        $this->builder = $builder;

        $this->gather($this->argumentSet);
    }

    public function hasSearchArguments(): bool
    {
        return count($this->searchArguments) > 0;
    }

    public function enhanceBuilder(): ScoutBuilder
    {
        if (count($this->searchArguments) > 1) {
            throw new ScoutException('Found more than 1 argument with @search.');
        }
        $searchArgument = $this->searchArguments[0];

        if (count($this->argumentsWithOnlyArgBuilders) > 0) {
            throw new ScoutException('Found arg builder arguments that do not work with @search');
        }

        if (! $this->builder instanceof EloquentBuilder) {
            throw new ScoutException('Can only get Model from \Illuminate\Database\Eloquent\Builder, got: ' . get_class($this->builder));
        }
        $model = $this->builder->getModel();

        if (! Utils::classUsesTrait($model, Searchable::class)) {
            throw new ScoutException('Model class ' . get_class($model) . ' does not implement trait ' . Searchable::class);
        }

        // @phpstan-ignore-next-line Can not use traits as types
        /**
         * @var \Illuminate\Database\Eloquent\Model&\Laravel\Scout\Searchable $model
         */
        $scoutBuilder = $model::search($searchArgument->toPlain());

        /**
         * We know this argument has this directive, because that is how we found it.
         *
         * @var \Nuwave\Lighthouse\Scout\SearchDirective $searchDirective
         */
        $searchDirective = $searchArgument
            ->directives
            ->first(Utils::instanceofMatcher(SearchDirective::class));

        $searchDirective->search($scoutBuilder);

        foreach ($this->argumentsWithScoutBuilderDirectives as $argument) {
            /** @var \Nuwave\Lighthouse\Scout\ScoutBuilderDirective $scoutBuilderDirective */
            $scoutBuilderDirective = $argument
                ->directives
                ->first(Utils::instanceofMatcher(ScoutBuilderDirective::class));

            $scoutBuilderDirective->handleScoutBuilder($scoutBuilder, $argument->toPlain());
        }

        return $scoutBuilder;
    }

    protected function gather(ArgumentSet $argumentSet): void
    {
        foreach ($argumentSet->arguments as $argument) {
            $argumentHasSearchDirective = $argument
                ->directives
                ->contains(Utils::instanceofMatcher(SearchDirective::class));

            if ($argumentHasSearchDirective && is_string($argument->value)) {
                $this->searchArguments[] = $argument;
            }

            $argumentHasArgBuilderDirective = $argument
                ->directives
                ->contains(Utils::instanceofMatcher(ArgBuilderDirective::class));

            $argumentHasScoutBuilderDirective = $argument
                ->directives
                ->contains(Utils::instanceofMatcher(ScoutBuilderDirective::class));

            if ($argumentHasArgBuilderDirective && ! $argumentHasScoutBuilderDirective) {
                $this->argumentsWithOnlyArgBuilders[] = $argument;
            }

            if ($argumentHasScoutBuilderDirective) {
                $this->argumentsWithScoutBuilderDirectives[] = $argument;
            }

            Utils::applyEach(
                function ($value) {
                    if ($value instanceof ArgumentSet) {
                        $this->gather($value);
                    }
                },
                $argument->value
            );
        }
    }
}
