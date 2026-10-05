<?php
namespace CMS\Agents;

use CMS\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Allowlisted, validated actions an AI agent may request.
 *
 * The model NEVER supplies SQL. It returns a JSON intent such as
 * {"action":"publish_post","params":{"post_id":42}} which this registry
 * validates against a fixed schema, authorizes against the acting user's
 * role, and executes via bound parameters.
 *
 * Adding capability means adding an entry here — never a raw query path.
 */
class IntentRegistry
{
    private Connection $db;
    private array $schema;
    private array $writers;

    /**
     * @param array $writers action => minimum role required.
     */
    public function __construct(Connection $db, array $schema, array $writers = [])
    {
        $this->db = $db;
        $this->schema = $schema;
        $this->writers = $writers;
    }

    public function actions(): array
    {
        return array_keys($this->schema);
    }

    /**
     * Execute an intent on behalf of a user.
     *
     * @param array  $intent   Decoded model output.
     * @param string $userRole Role of the acting user.
     *
     * @return array Result payload.
     * @throws InvalidArgumentException when the action is unknown or params fail validation.
     * @throws RuntimeException         when the user lacks the required role.
     */
    public function execute(array $intent, string $userRole = 'author'): array
    {
        $action = $intent['action'] ?? null;

        if (!is_string($action) || !isset($this->schema[$action])) {
            throw new InvalidArgumentException(
                'Unknown action: ' . var_export($action, true) . '. Allowed: ' . implode(', ', $this->actions())
            );
        }

        $spec = $this->schema[$action];

        // Authorize: read-only intents need no elevation; writes require their role.
        $required = $this->writers[$action] ?? null;
        if ($required !== null) {
            $rank = [ 'author' => 1, 'editor' => 2, 'admin' => 3 ];
            $have = $rank[$userRole] ?? 0;
            if ($have < ($rank[$required] ?? 99)) {
                throw new RuntimeException(
                    "Action '{$action}' requires {$required} role; caller is {$userRole}"
                );
            }
        }

        $params = $this->validate($spec['params'], $intent['params'] ?? []);

        return $spec['handler']($params);
    }

    /**
     * Validate params against a declarative spec, then cast them.
     *
     * spec: [ 'name' => ['type' => 'int|id|string|bool', 'required' => true] ]
     */
    private function validate(array $spec, array $params): array
    {
        $clean = [];

        foreach ($spec as $name => $rule) {
            $type = $rule['type'] ?? 'string';
            $required = $rule['required'] ?? false;
            $present = array_key_exists($name, $params);
            $value = $params[$name] ?? null;

            if ($required && (!$present || $value === null || $value === '')) {
                throw new InvalidArgumentException("Missing required parameter: {$name}");
            }
            if (!$present || $value === null) {
                continue;
            }

            $clean[$name] = $this->cast($name, $value, $type);
        }

        // Reject unexpected keys so a model cannot smuggle extra fields through.
        $unknown = array_diff(array_keys($params), array_keys($spec));
        if ($unknown) {
            throw new InvalidArgumentException(
                'Unexpected parameter(s): ' . implode(', ', $unknown)
            );
        }

        return $clean;
    }

    private function cast(string $name, mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => $this->castInt($name, $value),
            'id'  => $this->castInt($name, $value, positive: true),
            'bool' => (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'slug' => $this->castSlug($name, $value),
            'string' => $this->castString($name, $value),
            default => throw new InvalidArgumentException("Unknown param type '{$type}' for {$name}"),
        };
    }

    private function castInt(string $name, mixed $value, bool $positive = false): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw new InvalidArgumentException("Parameter '{$name}' must be an integer");
        }
        $int = (int) $value;
        if ($positive && $int < 1) {
            throw new InvalidArgumentException("Parameter '{$name}' must be a positive integer");
        }
        return $int;
    }

    private function castString(string $name, mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Parameter '{$name}' must be a string");
        }
        $len = strlen($value);
        if ($len > 5000) {
            throw new InvalidArgumentException("Parameter '{$name}' exceeds 5000 characters");
        }
        return $value;
    }

    private function castSlug(string $name, mixed $value): string
    {
        $slug = strtolower(trim((string) $value));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException("Parameter '{$name}' is not a valid slug");
        }
        return $slug;
    }
}