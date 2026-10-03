<?php

namespace Tests\Support;

/**
 * Checks a decoded JSON response against the subset of JSON Schema the v2 contract
 * uses: type (including the `[string, 'null']` form), const, enum, pattern,
 * properties / required / additionalProperties, items, and $ref / oneOf / anyOf /
 * allOf. Anything else in the schema is ignored, so the checker can only ever be
 * too lenient, never wrongly strict.
 *
 * Kept deliberately small and dependency-free: the point is that the contract and
 * the code cannot drift without the suite noticing, not a complete validator.
 */
final class OpenApiSchemaChecker
{
    /** @var array<string, mixed> */
    private array $spec;

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    /**
     * The problems found, as "path: problem" lines. Empty when the value conforms.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function check(mixed $value, array $schema, string $path = '$'): array
    {
        $this->errors = [];
        $this->walk($value, $schema, $path);

        return $this->errors;
    }

    /**
     * The response schema the spec declares for a path, method and status.
     *
     * @return array<string, mixed>
     */
    public function responseSchema(string $path, string $method, int $status): array
    {
        $operation = $this->spec['paths'][$path][strtolower($method)] ?? null;

        if ($operation === null) {
            throw new \RuntimeException("The contract has no {$method} {$path}.");
        }

        $response = $operation['responses'][(string) $status] ?? null;

        if ($response === null) {
            throw new \RuntimeException("The contract has no {$status} response for {$method} {$path}.");
        }

        $response = $this->deref($response);

        $schema = $response['content']['application/json']['schema'] ?? null;

        if ($schema === null) {
            throw new \RuntimeException("The {$status} response for {$method} {$path} has no JSON schema.");
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function walk(mixed $value, array $schema, string $path): void
    {
        $schema = $this->deref($schema);

        if (isset($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                $this->walk($value, $branch, $path);
            }
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (! isset($schema[$keyword])) {
                continue;
            }

            $failures = [];
            foreach ($schema[$keyword] as $branch) {
                $checker = new self($this->spec);
                $problems = $checker->check($value, $branch, $path);
                if ($problems === []) {
                    return;
                }
                $failures[] = $problems;
            }

            $this->errors[] = "{$path}: matched none of the {$keyword} branches (".implode(' | ', array_map(fn ($p) => implode('; ', $p), $failures)).')';

            return;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $this->errors[] = "{$path}: expected the constant ".json_encode($schema['const']).', got '.json_encode($value);

            return;
        }

        if (isset($schema['type']) && ! $this->matchesType($value, $schema['type'])) {
            $this->errors[] = "{$path}: expected type ".json_encode($schema['type']).', got '.$this->describe($value);

            return;
        }

        if ($value === null) {
            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $this->errors[] = "{$path}: ".json_encode($value).' is not one of the allowed values';
        }

        if (isset($schema['pattern']) && is_string($value) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value) !== 1) {
            $this->errors[] = "{$path}: ".json_encode($value)." does not match {$schema['pattern']}";
        }

        if (is_array($value) && $this->isList($value, $schema)) {
            if (isset($schema['items']) && $schema['items'] !== []) {
                foreach ($value as $index => $item) {
                    $this->walk($item, $schema['items'], "{$path}[{$index}]");
                }
            }

            return;
        }

        if (is_array($value)) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $this->errors[] = "{$path}: missing required property '{$required}'";
                }
            }

            $properties = (array) ($schema['properties'] ?? []);

            foreach ($value as $key => $item) {
                if (isset($properties[$key])) {
                    $this->walk($item, $properties[$key], "{$path}.{$key}");
                } elseif (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                    $this->walk($item, $schema['additionalProperties'], "{$path}.{$key}");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $this->errors[] = "{$path}: unexpected property '{$key}'";
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function deref(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            unset($schema['$ref']);

            if (! str_starts_with($ref, '#/')) {
                throw new \RuntimeException("Only local references are supported, got {$ref}.");
            }

            $target = $this->spec;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    throw new \RuntimeException("Dangling reference {$ref}.");
                }
                $target = $target[$segment];
            }

            // Siblings of a $ref (a description, say) are kept; the target wins on conflict.
            $schema = array_merge($schema, $target);
        }

        return $schema;
    }

    /**
     * @param  string|list<string>  $type
     */
    private function matchesType(mixed $value, string|array $type): bool
    {
        foreach ((array) $type as $candidate) {
            $ok = match ($candidate) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                // An empty JSON object decodes to an empty PHP array, which is also a list.
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => true,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function isList(array $value, array $schema): bool
    {
        $declared = (array) ($schema['type'] ?? []);

        if (in_array('array', $declared, true) && ! in_array('object', $declared, true)) {
            return true;
        }

        if (in_array('object', $declared, true)) {
            return false;
        }

        return array_is_list($value) && $value !== [];
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string '.json_encode($value),
            is_array($value) && array_is_list($value) => 'array',
            default => 'object',
        };
    }
}
