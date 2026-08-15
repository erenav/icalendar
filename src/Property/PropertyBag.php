<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Property;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * An immutable, ordered list of a component's properties.
 *
 * Unlike a map, it preserves both insertion order and duplicate names (multiple
 * ATTENDEE properties, repeated unmodelled `X-` properties). That ordering
 * enables stable canonical re-export and semantic preservation of supported
 * unknown properties.
 *
 * @implements IteratorAggregate<int, Property>
 */
final readonly class PropertyBag implements Countable, IteratorAggregate
{
    /** @var list<Property> */
    private array $properties;

    public function __construct(Property ...$properties)
    {
        $this->properties = array_values($properties);
    }

    /** Return a new bag with $property appended. */
    public function with(Property $property): self
    {
        return new self(...[...$this->properties, $property]);
    }

    /** Return a new bag with every property of the given name removed. */
    public function without(string $name): self
    {
        $name = strtoupper($name);

        return new self(...array_filter(
            $this->properties,
            static fn (Property $property): bool => $property->name !== $name,
        ));
    }

    /** The first property with the given name, or null. */
    public function first(string $name): ?Property
    {
        $name = strtoupper($name);
        foreach ($this->properties as $property) {
            if ($property->name === $name) {
                return $property;
            }
        }

        return null;
    }

    /**
     * All properties, or all with a given name, in document order.
     *
     * @return list<Property>
     */
    public function all(?string $name = null): array
    {
        if ($name === null) {
            return $this->properties;
        }

        $name = strtoupper($name);

        return array_values(array_filter(
            $this->properties,
            static fn (Property $property): bool => $property->name === $name,
        ));
    }

    public function has(string $name): bool
    {
        return $this->first($name) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->properties === [];
    }

    public function count(): int
    {
        return count($this->properties);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->properties);
    }
}
