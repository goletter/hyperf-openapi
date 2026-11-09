<?php

declare(strict_types=1);

namespace Goletter\OpenApi\Scanner;

use Hyperf\Stringable\Str;

/**
 * 从 Request / Resource / Model 源码推断 OpenAPI schema。
 */
final class SchemaInferrer
{
    /**
     * 解析 FormRequest：rules + attributes → properties / required / descriptions。
     * rules 为空时仍返回空结构，便于区分「已定位 Request」与「未找到文件」。
     *
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}|null
     */
    public function fromRequestFile(string $file): ?array
    {
        $code = $this->read($file);
        if ($code === null) {
            return null;
        }

        $rules = $this->parseStringArrayMethod($code, 'rules');
        $attributes = $this->parseStringArrayMethod($code, 'attributes');

        $properties = [];
        $required = [];
        foreach ($rules as $field => $rule) {
            $schema = $this->ruleToSchema($rule);
            if (isset($attributes[$field]) && $attributes[$field] !== '') {
                $schema['description'] = $attributes[$field];
                $schema['title'] = $attributes[$field];
            }
            $properties[$field] = $schema;
            if (preg_match('/(?<![a-z])required(?![a-z])/', strtolower($rule))) {
                $required[] = $field;
            }
        }

        return [
            'properties' => $properties,
            'required' => array_values(array_unique($required)),
        ];
    }


    /**
     * 写接口 body：仅使用 FormRequest::rules()/attributes()，不用 migration/数据库补全。
     *
     * @param array{properties: array<string, array<string, mixed>>, required: list<string>}|null $requestSchema
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}
     */
    public function forWriteBody(?array $requestSchema): array
    {
        return [
            'properties' => $requestSchema['properties'] ?? [],
            'required' => array_values(array_unique($requestSchema['required'] ?? [])),
        ];
    }


    /**
     * ModelFilter 公开方法 → query 参数（snake_case）；可选附带分页 page/pageSize。
     *
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}
     */
    public function fromFilterFile(string $file, bool $withPagination = true): array
    {
        $properties = $withPagination ? $this->paginationQueryParams() : [];
        $code = ($file !== '' && is_file($file)) ? $this->read($file) : null;
        if ($code !== null && preg_match_all(
            '/public\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $code,
            $matches
        )) {
            $skip = [
                '__construct', '__call', 'handle', 'input', 'blacklist', 'push',
                'filterInput', 'filterRelations', 'related', 'addRelated',
                'disableRelations', 'enableRelations', 'relationsEnabled',
                'getFilterMethod', 'removeEmptyInput', 'getAllRelations', 'applyFilterResult',
            ];
            foreach ($matches[1] as $method) {
                if (in_array($method, $skip, true) || str_starts_with($method, '__') || str_ends_with($method, 'Setup')) {
                    continue;
                }
                $param = Str::snake($method);
                $properties[$param] = [
                    'type' => 'string',
                    'description' => $param === $method
                        ? '筛选：' . $param
                        : sprintf('筛选：%s（亦可传 %s）', $param, $method),
                ];
            }
        }

        return [
            'properties' => $properties,
            'required' => [],
        ];
    }

    /**
     * 列表分页 query（对齐 Service paginate pageSize）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function paginationQueryParams(): array
    {
        return [
            'page' => [
                'type' => 'integer',
                'description' => '页码',
                'example' => 1,
            ],
            'pageSize' => [
                'type' => 'integer',
                'description' => '每页条数',
                'example' => 20,
            ],
        ];
    }

    /**
     * Resource → data 对象 schema；优先 toArray，其次 Model，再迁移；并合并 availableIncludes 关联。
     *
     * @return array{schema: array<string, mixed>, includes: list<string>}
     */
    public function fromResourceFile(string $file, string $resourceClass, bool $collection = false): array
    {
        $code = $this->read($file);
        $properties = [];

        if ($code !== null) {
            $properties = $this->parseToArrayKeys($code);
        }

        $base = class_basename_safe($resourceClass);
        $modelBase = str_ends_with($base, 'Resource') ? substr($base, 0, -8) : $base;

        if ($properties === []) {
            $modelFile = $this->guessModelFile($resourceClass);
            if ($modelFile !== null) {
                $properties = $this->fromModelFile($modelFile);
            }
        }

        if ($properties === [] && $modelBase !== '') {
            $properties = $this->fromMigration($modelBase);
        }

        $includes = [];
        if ($code !== null) {
            $includes = $this->parseAvailableIncludes($code, $collection);
            foreach ($includes as $include) {
                $relationSchema = $this->relationSchemaFromInclude(
                    $resourceClass,
                    $code,
                    $modelBase,
                    $include
                );
                // Model::toArray 默认 snake 关联名：platformConfig → platform_config
                $key = Str::snake(str_replace('.', '_', $include));
                $properties[$key] = $relationSchema;
            }
        }

        $schema = $properties === []
            ? ['type' => 'object', 'description' => $base]
            : [
                'type' => 'object',
                'description' => $base,
                'properties' => $properties,
            ];

        return [
            'schema' => $schema,
            'includes' => $includes,
        ];
    }

    /**
     * @return list<string>
     */
    public function parseAvailableIncludes(string $code, bool $collection = false): array
    {
        $prop = $collection ? 'collectionAvailableIncludes' : 'availableIncludes';
        $list = $this->parseStringListProperty($code, $prop);
        if ($list === [] && $collection) {
            // 列表未单独声明时回退单资源 includes
            $list = $this->parseStringListProperty($code, 'availableIncludes');
        }

        return array_values(array_filter($list, static fn ($v) => is_string($v) && $v !== ''));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fromModelFile(string $file): array
    {
        $code = $this->read($file);
        if ($code === null) {
            return [];
        }

        $fields = $this->parseStringListProperty($code, 'fillable');
        if ($fields === [] && preg_match('/\$casts\s*=\s*\[(.*?)\];/s', $code, $m)) {
            $fields = array_keys($this->parseAssocArrayBody($m[1]));
        }

        $properties = [];
        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }
            $properties[$field] = ['type' => 'string', 'description' => $field];
        }

        return $properties;
    }

    /**
     * 统一成功响应信封。
     *
     * 默认对齐 ResponseFormatMiddleware 最终输出：
     * - 单条：success/status/code/message/data
     * - 列表：同上 + 顶层 total（中间件会把 meta.total 提升并去掉 meta/links）
     *
     * @param array<string, mixed> $dataSchema
     * @return array<string, mixed>
     */
    public function wrapSuccessEnvelope(array $dataSchema, bool $collection = false, string $style = 'formatted'): array
    {
        $data = $collection
            ? ['type' => 'array', 'items' => $dataSchema]
            : $dataSchema;

        $properties = [
            'success' => ['type' => 'boolean', 'example' => true],
            'status' => ['type' => 'string', 'example' => 'success'],
            'code' => ['type' => 'integer', 'example' => 200],
            'message' => ['type' => 'string', 'example' => ''],
            'data' => $data,
        ];

        if ($collection) {
            if ($style === 'raw') {
                // Resource 原始输出（未经 ResponseFormatMiddleware）
                $properties['meta'] = [
                    'type' => 'object',
                    'description' => '分页信息（列表接口）',
                    'properties' => [
                        'total' => ['type' => 'integer'],
                        'current_page' => ['type' => 'integer'],
                        'last_page' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                    ],
                ];
            } else {
                // ResponseFormatMiddleware：unset meta/links，并写入顶层 total
                $properties['total'] = [
                    'type' => 'integer',
                    'description' => '总条数（ResponseFormatMiddleware 由 meta.total 提升）',
                    'example' => 0,
                ];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    /**
     * 从迁移推断字段：合并 create_{table}_table 与后续 alter（如 *_to_{table}_table）。
     * 按文件名时间序叠加；只解析 up()，避免 down() 里的 dropColumn 冲掉字段。
     *
     * @return array<string, array<string, mixed>>
     */
    public function fromMigration(string $modelBase): array
    {
        if (! defined('BASE_PATH')) {
            return [];
        }
        $table = $this->guessTableName($modelBase);
        $dir = BASE_PATH . '/migrations';
        if ($table === '' || ! is_dir($dir)) {
            return [];
        }

        // create + rename/alter（add_*_to_{table}_table）等，按时间戳排序后合并
        $files = array_values(array_unique(array_merge(
            glob($dir . '/*_create_' . $table . '_table.php') ?: [],
            glob($dir . '/*_to_' . $table . '_table.php') ?: [],
            glob($dir . '/*_' . $table . '_table.php') ?: [],
        )));
        if ($files === []) {
            $files = glob($dir . '/*' . $table . '*.php') ?: [];
        }
        if ($files === []) {
            return [];
        }
        sort($files);

        $properties = [];
        foreach ($files as $file) {
            $code = $this->read((string) $file);
            if ($code === null) {
                continue;
            }
            $upBody = $this->extractMigrationUpBody($code);
            foreach ($this->parseMigrationColumns($upBody !== '' ? $upBody : $code) as $field => $schema) {
                if (($schema['_drop'] ?? false) === true) {
                    unset($properties[$field], $schema['_drop']);
                    continue;
                }
                unset($schema['_drop']);
                $properties[$field] = $schema;
            }
        }

        return $properties;
    }

    /** 截取 up() 方法体；失败则返回空串由调用方回退整文件。 */
    private function extractMigrationUpBody(string $code): string
    {
        if (! preg_match('/function\s+up\s*\([^)]*\)\s*(?::\s*\??[\w|\\\\]+\s*)?\{/s', $code, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        if (preg_match('/function\s+down\s*\(/s', $code, $dm, PREG_OFFSET_CAPTURE, $start)) {
            return substr($code, $start, (int) $dm[0][1] - $start);
        }

        return substr($code, $start);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseMigrationColumns(string $code): array
    {
        $properties = [];
        $skip = [
            'index', 'unique', 'primary', 'foreign', 'timestamps', 'datetimes',
            'softDeletes', 'rememberToken', 'comment', 'renameColumn', 'rename',
        ];
        // 按行解析，避免 comment 文案中的分号截断整句
        foreach (preg_split('/\r\n|\n|\r/', $code) ?: [] as $line) {
            if (! preg_match('/\$table->(\w+)\(\s*[\'"]([\w]+)[\'"]/', $line, $m)) {
                continue;
            }
            $method = $m[1];
            $field = $m[2];
            if (in_array($method, $skip, true)) {
                continue;
            }
            // up() 里直接 drop 时标记删除，供 fromMigration 合并时 unset
            if (strcasecmp($method, 'dropColumn') === 0) {
                $properties[$field] = ['_drop' => true];
                continue;
            }
            $schema = $this->migrationTypeToSchema($method);
            if (preg_match('/comment\(\s*[\'"]([^\'"]*)[\'"]\s*\)/u', $line, $cm)) {
                $schema['description'] = $cm[1];
            } else {
                $schema['description'] = $field;
            }
            $properties[$field] = $schema;
        }

        // datetimes() / timestamps() → created_at / updated_at
        if (preg_match('/\$table->(datetimes|timestamps)\s*\(/', $code)) {
            $properties['created_at'] ??= ['type' => 'string', 'format' => 'date-time', 'description' => 'created_at'];
            $properties['updated_at'] ??= ['type' => 'string', 'format' => 'date-time', 'description' => 'updated_at'];
        }

        // Db::statement("ALTER TABLE ... MODIFY/ADD COLUMN ... COMMENT '...'")
        foreach ($this->parseAlterSqlColumns($code) as $field => $schema) {
            if (isset($properties[$field])) {
                if (! empty($schema['description'])) {
                    $properties[$field]['description'] = $schema['description'];
                }
                if (! empty($schema['type'])) {
                    $properties[$field]['type'] = $schema['type'];
                }
                if (! empty($schema['format'])) {
                    $properties[$field]['format'] = $schema['format'];
                }
            } else {
                $properties[$field] = $schema;
            }
        }

        return $properties;
    }

    /**
     * 解析迁移里原生 SQL：ALTER TABLE ... ADD|MODIFY|CHANGE COLUMN `field` TYPE ... COMMENT '...'.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseAlterSqlColumns(string $code): array
    {
        $properties = [];
        $typePart = '(\w+(?:\s*\(\s*\d+(?:\s*,\s*\d+)?\s*\))?)';
        $commentPart = '(?:.*?COMMENT\s+[\'"]([^\'"]*)[\'"])?';

        // ADD|MODIFY COLUMN `field` TYPE ... COMMENT '...'
        if (preg_match_all(
            '/\b(?:ADD|MODIFY)(?:\s+COLUMN)?\s+[`]?(\w+)[`]?\s+' . $typePart . $commentPart . '/is',
            $code,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $this->mergeAlterColumn($properties, $row[1], $row[2], $row[3] ?? '');
            }
        }

        // CHANGE COLUMN `old` `new` TYPE ... COMMENT '...'
        if (preg_match_all(
            '/\bCHANGE(?:\s+COLUMN)?\s+[`]?(\w+)[`]?\s+[`]?(\w+)[`]?\s+' . $typePart . $commentPart . '/is',
            $code,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $this->mergeAlterColumn($properties, $row[2], $row[3], $row[4] ?? '');
            }
        }

        return $properties;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    private function mergeAlterColumn(array &$properties, string $field, string $sqlType, string $comment): void
    {
        $field = trim($field);
        if ($field === '' || in_array(strtolower($field), ['table', 'column', 'if', 'not', 'exists'], true)) {
            return;
        }
        $schema = $this->sqlTypeToSchema(strtolower((string) preg_replace('/\s+/', '', $sqlType)));
        $comment = trim($comment);
        $schema['description'] = $comment !== '' ? $comment : $field;
        $properties[$field] = $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function sqlTypeToSchema(string $sqlType): array
    {
        $base = strtolower((string) preg_replace('/\(.*\)$/', '', $sqlType));

        return match ($base) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' => ['type' => 'integer'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'float', 'double', 'decimal', 'numeric' => ['type' => 'number'],
            'json', 'jsonb' => ['type' => 'object'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationTypeToSchema(string $method): array
    {
        return match (strtolower($method)) {
            'bigincrements', 'increments', 'id', 'integer', 'tinyinteger', 'smallinteger', 'biginteger', 'unsignedinteger', 'unsignedbiginteger' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'float', 'double', 'decimal' => ['type' => 'number'],
            'json', 'jsonb' => ['type' => 'object'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'datetimetz', 'timestamp', 'timestamptz' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }

    private function guessTableName(string $modelBase): string
    {
        if ($modelBase === '') {
            return '';
        }

        return Str::plural(Str::snake($modelBase));
    }

    /**
     * @return array<string, string> field => ruleString
     */
    private function parseStringArrayMethod(string $code, string $method): array
    {
        if ($method !== '') {
            if (! preg_match(
                '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{[^\{]*?return\s*\[(.*?)\];/s',
                $code,
                $m
            )) {
                return [];
            }

            return $this->parseAssocArrayBody($m[1]);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function parseStringListProperty(string $code, string $property): array
    {
        if (! preg_match('/\$' . preg_quote($property, '/') . '\s*=\s*\[(.*?)\];/s', $code, $m)) {
            return [];
        }
        $body = $m[1];
        if (! preg_match_all("/['\"]([A-Za-z0-9_.]+)['\"]/", $body, $keys)) {
            return [];
        }

        return $keys[1];
    }

    /**
     * @return array<string, string>
     */
    private function parseAssocArrayBody(string $body): array
    {
        $result = [];

        // 'field' => 'required|string|max:100'
        if (preg_match_all(
            "/['\"]([A-Za-z0-9_.]+)['\"]\s*=>\s*['\"]([^'\"]*)['\"]/",
            $body,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $result[$row[1]] = $row[2];
            }
        }

        // 'field' => ['required', 'string', 'max:100']
        if (preg_match_all(
            "/['\"]([A-Za-z0-9_.]+)['\"]\s*=>\s*\[(.*?)\]/s",
            $body,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $field = $row[1];
                if (isset($result[$field])) {
                    continue;
                }
                $parts = [];
                if (preg_match_all("/['\"]([^'\"]+)['\"]/", $row[2], $items)) {
                    $parts = $items[1];
                }
                $result[$field] = $parts !== [] ? implode('|', $parts) : 'string';
            }
        }

        return $result;
    }



    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseToArrayKeys(string $code): array
    {
        if (! preg_match('/function\s+toArray\s*\([^)]*\)[^{]*\{(.*)\n\s*\}/s', $code, $m)) {
            return [];
        }
        $body = $m[1];
        if (! preg_match('/return\s*\[(.*)\];/s', $body, $ret)) {
            return [];
        }
        $properties = [];
        if (preg_match_all("/['\"]([A-Za-z0-9_.]+)['\"]\s*=>/", $ret[1], $keys)) {
            foreach ($keys[1] as $field) {
                $properties[$field] = ['type' => 'string', 'description' => $field];
            }
        }

        return $properties;
    }

    private function guessModelFile(string $resourceClass): ?string
    {
        $base = class_basename_safe($resourceClass);
        $base = str_ends_with($base, 'Resource') ? substr($base, 0, -8) : $base;
        if ($base === '') {
            return null;
        }
        $file = BASE_PATH . '/app/Model/' . $base . '.php';

        return is_file($file) ? $file : null;
    }

    /**
     * 根据 include 名推断关联 schema（优先同名 Resource，其次 Model 关系返回类型 / 迁移）。
     *
     * @return array<string, mixed>
     */
    private function relationSchemaFromInclude(
        string $resourceClass,
        string $resourceCode,
        string $ownerModelBase,
        string $include
    ): array {
        $relation = Str::camel(str_replace('.', '_', $include));
        $relatedModel = $this->guessRelatedModelName($ownerModelBase, $relation);
        $isMany = $this->relationIsMany($ownerModelBase, $relation, $resourceCode, $relation);

        // {Relation}Query 里 select([...]) 可收窄字段
        $selected = $this->parseRelationQuerySelect($resourceCode, $relation);

        $relatedProps = [];
        $relatedResourceFile = $this->guessRelatedResourceFile($resourceClass, $relatedModel);
        if ($relatedResourceFile !== null) {
            // 嵌套关联不再递归展开 includes，避免环
            $nested = $this->fromResourceFileBase($relatedResourceFile, $relatedModel . 'Resource');
            $relatedProps = $nested['properties'] ?? [];
        }
        if ($relatedProps === [] && $relatedModel !== '') {
            $relatedProps = $this->fromMigration($relatedModel);
            if ($relatedProps === []) {
                $modelFile = BASE_PATH . '/app/Model/' . $relatedModel . '.php';
                if (is_file($modelFile)) {
                    $relatedProps = $this->fromModelFile($modelFile);
                }
            }
        }
        if ($selected !== []) {
            $filtered = [];
            foreach ($selected as $field) {
                $filtered[$field] = $relatedProps[$field] ?? ['type' => 'string', 'description' => $field];
            }
            $relatedProps = $filtered;
        }

        $objectSchema = [
            'type' => 'object',
            'description' => $relatedModel !== '' ? $relatedModel : $include,
            'nullable' => true,
        ];
        if ($relatedProps !== []) {
            $objectSchema['properties'] = $relatedProps;
        }

        if ($isMany) {
            return [
                'type' => 'array',
                'description' => sprintf('关联 %s（?include=%s）', $include, $include),
                'items' => $objectSchema,
            ];
        }

        $objectSchema['description'] = sprintf('关联 %s（?include=%s）', $include, $include);

        return $objectSchema;
    }

    /**
     * 仅解析 Resource 自身字段（不含 includes），供嵌套关联使用。
     *
     * @return array{type: string, description?: string, properties?: array<string, array<string, mixed>>}
     */
    private function fromResourceFileBase(string $file, string $resourceClass): array
    {
        $code = $this->read($file);
        $properties = [];
        if ($code !== null) {
            $properties = $this->parseToArrayKeys($code);
        }
        $base = class_basename_safe($resourceClass);
        $modelBase = str_ends_with($base, 'Resource') ? substr($base, 0, -8) : $base;
        if ($properties === []) {
            $modelFile = $this->guessModelFile($resourceClass);
            if ($modelFile !== null) {
                $properties = $this->fromModelFile($modelFile);
            }
        }
        if ($properties === [] && $modelBase !== '') {
            $properties = $this->fromMigration($modelBase);
        }
        if ($properties === []) {
            return ['type' => 'object', 'description' => $base];
        }

        return [
            'type' => 'object',
            'description' => $base,
            'properties' => $properties,
        ];
    }

    private function guessRelatedModelName(string $ownerModelBase, string $relationCamel): string
    {
        if ($ownerModelBase !== '') {
            $modelFile = BASE_PATH . '/app/Model/' . $ownerModelBase . '.php';
            $code = is_file($modelFile) ? $this->read($modelFile) : null;
            if ($code !== null
                && preg_match(
                    '/function\s+' . preg_quote($relationCamel, '/') . '\s*\([^)]*\)[^{]*\{[^}]*\b(?:belongsTo|hasOne|hasMany|belongsToMany|morphTo|morphMany)\s*\(\s*([A-Za-z_][\w\\\\]*)::class/s',
                    $code,
                    $m
                )
            ) {
                return class_basename_safe($m[1]);
            }
        }

        // platformConfig → PlatformConfig
        return Str::studly($relationCamel);
    }

    private function relationIsMany(
        string $ownerModelBase,
        string $relationCamel,
        string $resourceCode,
        string $relation
    ): bool {
        if (preg_match(
            '/function\s+' . preg_quote($relation, '/') . 'Query\s*\(\s*\\\\?([A-Za-z\\\\]+)/',
            $resourceCode,
            $m
        )) {
            $type = class_basename_safe($m[1]);
            if (in_array($type, ['HasMany', 'BelongsToMany', 'MorphMany', 'HasManyThrough'], true)) {
                return true;
            }
            if (in_array($type, ['BelongsTo', 'HasOne', 'MorphTo', 'MorphOne'], true)) {
                return false;
            }
        }

        $modelFile = $ownerModelBase !== '' ? BASE_PATH . '/app/Model/' . $ownerModelBase . '.php' : null;
        $code = ($modelFile !== null && is_file($modelFile)) ? $this->read($modelFile) : null;
        if ($code !== null
            && preg_match(
                '/function\s+' . preg_quote($relationCamel, '/') . '\s*\([^)]*\)[^{]*\{[^}]*\b(hasMany|belongsToMany|morphMany|hasManyThrough|belongsTo|hasOne|morphTo|morphOne)\s*\(/s',
                $code,
                $m
            )
        ) {
            return in_array(strtolower($m[1]), ['hasmany', 'belongstomany', 'morphmany', 'hasmanythrough'], true);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function parseRelationQuerySelect(string $resourceCode, string $relationCamel): array
    {
        $method = $relationCamel . 'Query';
        if (! preg_match(
            '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\}/s',
            $resourceCode,
            $m
        )) {
            return [];
        }
        $body = $m[1];
        if (! preg_match('/select\s*\(\s*\[(.*?)\]\s*\)/s', $body, $sel)) {
            return [];
        }
        if (! preg_match_all("/['\"]([A-Za-z0-9_]+)['\"]/", $sel[1], $fields)) {
            return [];
        }

        return $fields[1];
    }

    private function guessRelatedResourceFile(string $ownerResourceClass, string $relatedModel): ?string
    {
        if ($relatedModel === '' || ! defined('BASE_PATH')) {
            return null;
        }
        $ns = $ownerResourceClass;
        if (str_contains($ns, '\\')) {
            $ns = substr($ns, 0, (int) strrpos($ns, '\\'));
        }
        // App\Resource\Api\V1 + PlatformConfigResource
        $file = BASE_PATH . '/app/' . substr(str_replace('\\', '/', $ns), 4) . '/' . $relatedModel . 'Resource.php';

        return is_file($file) ? $file : null;
    }

    private function ruleToSchema(string $rules): array
    {
        $parts = array_map('trim', explode('|', strtolower($rules)));
        $schema = ['type' => 'string'];
        $nullable = false;

        foreach ($parts as $part) {
            if ($part === 'nullable') {
                $nullable = true;
            } elseif ($part === 'integer' || $part === 'int') {
                $schema['type'] = 'integer';
            } elseif ($part === 'numeric') {
                $schema['type'] = 'number';
            } elseif ($part === 'boolean' || $part === 'bool') {
                $schema['type'] = 'boolean';
            } elseif ($part === 'array') {
                $schema['type'] = 'array';
                $schema['items'] = ['type' => 'string'];
            } elseif ($part === 'json') {
                $schema['type'] = 'object';
            } elseif ($part === 'email') {
                $schema['type'] = 'string';
                $schema['format'] = 'email';
            } elseif ($part === 'url' || $part === 'active_url') {
                $schema['type'] = 'string';
                $schema['format'] = 'uri';
            } elseif ($part === 'uuid') {
                $schema['type'] = 'string';
                $schema['format'] = 'uuid';
            } elseif ($part === 'date') {
                $schema['type'] = 'string';
                $schema['format'] = 'date';
            } elseif ($part === 'datetime' || str_starts_with($part, 'date_format:')) {
                $schema['type'] = 'string';
                $schema['format'] = 'date-time';
            } elseif (str_starts_with($part, 'in:')) {
                $enum = array_values(array_filter(
                    array_map('trim', explode(',', substr($part, 3))),
                    static fn ($v) => $v !== ''
                ));
                if ($enum !== []) {
                    $schema['enum'] = array_map(
                        static function (string $v) {
                            if (is_numeric($v) && ! str_contains($v, '.')) {
                                return (int) $v;
                            }
                            if (is_numeric($v)) {
                                return (float) $v;
                            }

                            return $v;
                        },
                        $enum
                    );
                }
            } elseif (str_starts_with($part, 'max:')) {
                $n = (int) substr($part, 4);
                if (($schema['type'] ?? '') === 'string') {
                    $schema['maxLength'] = $n;
                } else {
                    $schema['maximum'] = $n;
                }
            } elseif (str_starts_with($part, 'min:')) {
                $n = (int) substr($part, 4);
                if (($schema['type'] ?? '') === 'string') {
                    $schema['minLength'] = $n;
                } else {
                    $schema['minimum'] = $n;
                }
            }
        }

        if ($nullable) {
            $schema['nullable'] = true;
        }

        return $schema;
    }


    private function read(string $file): ?string
    {
        if (! is_file($file)) {
            return null;
        }
        $code = file_get_contents($file);
        if ($code === false) {
            return null;
        }

        return $this->ensureUtf8($code);
    }

    private function ensureUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            if (function_exists('mb_scrub')) {
                $value = mb_scrub($value, 'UTF-8');
            } else {
                $ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                $value = is_string($ignored) ? $ignored : '';
            }
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
    }
}

/**
 * @internal
 */
function class_basename_safe(string $class): string
{
    $class = str_replace('/', '\\', $class);
    if (str_contains($class, '\\')) {
        return substr($class, strrpos($class, '\\') + 1);
    }

    return $class;
}
