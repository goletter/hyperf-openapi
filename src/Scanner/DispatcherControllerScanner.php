<?php

declare(strict_types=1);

namespace Goletter\OpenApi\Scanner;

use Hyperf\Stringable\Str;

/**
 * 扫描分发式 Controller：
 * - 类/方法注释 → summary、description、tag
 * - Request::rules/attributes → requestBody
 * - Resource（+ Model）→ 响应 data
 */
final class DispatcherControllerScanner
{
    /**
     * @var array<string, array{0: string, 1: bool}>
     */
    private const REST_ACTIONS = [
        'index' => ['get', false],
        'show' => ['get', true],
        'get' => ['get', true],
        'store' => ['post', false],
        'create' => ['post', false],
        'update' => ['put', true],
        'patch' => ['patch', true],
        'destroy' => ['delete', true],
        'delete' => ['delete', true],
    ];

    /** @var list<string> */
    private array $skipMethods = [
        '__construct',
        'handle',
        'handleResource',
        'dispatch',
        'dispatchAction',
    ];

    /** @var list<string> */
    private array $debug = [];

    private SchemaInferrer $schemas;

    public function __construct()
    {
        $this->schemas = new SchemaInferrer();
    }

    /**
     * @return list<string>
     */
    public function getDebug(): array
    {
        return $this->debug;
    }

    /**
     * @param list<array<string, mixed>> $gateways
     * @return list<array<string, mixed>>
     */
    public function scan(
        array $gateways,
        string $resourceCase = 'snake_plural',
        bool $security = true,
        string $envelopeStyle = 'formatted'
    ): array {
        $this->debug = [];
        $operations = [];
        $envelopeStyle = $envelopeStyle === 'raw' ? 'raw' : 'formatted';

        foreach ($gateways as $gateway) {
            $prefix = rtrim((string) ($gateway['prefix'] ?? ''), '/');
            $namespace = rtrim((string) ($gateway['namespace'] ?? ''), '\\');
            $basePath = (string) ($gateway['path'] ?? '');
            $folder = $this->resolveGatewayFolder($gateway);
            $versions = $gateway['versions'] ?? null;

            if ($namespace === '' || $basePath === '') {
                $this->debug[] = 'skip: namespace/path 为空';
                continue;
            }
            if (! is_dir($basePath)) {
                $this->debug[] = 'skip: 目录不存在 ' . $basePath;
                continue;
            }

            $controllers = $this->findControllers($basePath, $namespace, is_array($versions) ? $versions : null);
            $this->debug[] = sprintf(
                'gateway %s folder=%s → %d Controllers',
                $prefix ?: '/',
                $folder,
                count($controllers)
            );

            foreach ($controllers as $meta) {
                $code = $this->ensureUtf8((string) file_get_contents($meta['file']));
                $classDoc = $this->parseClassDoc($code);
                $resourceSeg = $this->resourceSegment($meta['resource'], $resourceCase);
                // Apifox 目录：{version}/{Api|Admin}/{模块名}，模块名取 Controller 类注释（如「需求管理」）
                $tag = $this->ensureUtf8(
                    $this->normalizeModuleFolder($classDoc['tag'], $meta['resource'])
                );
                $apifoxFolder = $this->ensureUtf8($meta['version'] . '/' . $folder . '/' . $tag);

                $methods = $this->mergeInheritedMethods($meta['class'], $code, $this->parsePublicMethods($code));
                $this->debug[] = sprintf(
                    '  %s [%s] folder=%s tag=%s methods=%s',
                    $meta['class'],
                    $meta['version'],
                    $apifoxFolder,
                    $tag,
                    $methods === [] ? '(无)' : implode(',', array_column($methods, 'name'))
                );

                foreach ($methods as $methodInfo) {
                    $name = $methodInfo['name'];
                    if (in_array($name, $this->skipMethods, true) || str_starts_with($name, '__')) {
                        continue;
                    }

                    $op = $this->buildOperation(
                        $prefix,
                        $resourceSeg,
                        $tag,
                        $apifoxFolder,
                        $classDoc,
                        $meta,
                        $methodInfo,
                        $security,
                        $envelopeStyle
                    );

                    $key = strtoupper($op['method']) . ' ' . $op['path'] . ' ' . $op['operationId'];
                    $operations[$key] = $op;
                }
            }
        }

        $this->debug[] = '合计 operations=' . count($operations);

        return array_values($operations);
    }

    /**
     * @param null|list<string> $versions
     * @return list<array{class: string, resource: string, version: string, file: string}>
     */
    private function findControllers(string $basePath, string $namespace, ?array $versions): array
    {
        $result = [];
        $basePath = realpath($basePath) ?: $basePath;
        $pattern = $basePath . DIRECTORY_SEPARATOR . 'V*' . DIRECTORY_SEPARATOR . '*Controller.php';
        $files = glob($pattern) ?: [];

        $allowed = null;
        if (is_array($versions) && $versions !== []) {
            $allowed = array_map(fn ($v) => $this->normalizeVersion((string) $v), $versions);
        }

        foreach ($files as $file) {
            $filename = basename($file);
            if ($filename === 'ApiController.php') {
                continue;
            }
            $versionDir = basename(dirname($file));
            if (! preg_match('/^V(\d+)$/i', $versionDir, $m)) {
                continue;
            }
            $version = 'v' . $m[1];
            if ($allowed !== null && ! in_array($version, $allowed, true)) {
                continue;
            }

            $short = substr($filename, 0, -4);
            $resource = str_ends_with($short, 'Controller') ? substr($short, 0, -10) : $short;
            if ($resource === '') {
                continue;
            }

            $result[] = [
                'class' => $namespace . '\\' . $versionDir . '\\' . $short,
                'resource' => $resource,
                'version' => $version,
                'file' => $file,
            ];
        }

        return $result;
    }

    private function normalizeVersion(string $version): string
    {
        $version = strtolower(trim($version));
        if ($version !== '' && $version[0] !== 'v') {
            $version = 'v' . $version;
        }

        return $version;
    }

    /**
     * @return array{tag: string, description: string}
     */
    private function parseClassDoc(string $code): array
    {
        $code = $this->ensureUtf8($code);
        if (! preg_match('/(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+/u', $code, $m, PREG_OFFSET_CAPTURE)) {
            return ['tag' => '', 'description' => ''];
        }

        $docRaw = $this->docblockBefore($code, (int) $m[0][1]);
        if ($docRaw === '' || ! preg_match('#/\*\*(.*?)\*/#s', $docRaw, $dm)) {
            return ['tag' => '', 'description' => ''];
        }

        $lines = [];
        foreach ($this->docLines($dm[1]) as $line) {
            if ($this->isFileHeaderLine($line)) {
                continue;
            }
            $cleaned = $this->stripDocAnnotationPrefix($line);
            if ($cleaned === null) {
                continue;
            }
            $lines[] = $this->ensureUtf8($cleaned);
        }
        $first = $lines[0] ?? '';
        if ($first === '') {
            return ['tag' => '', 'description' => ''];
        }

        return [
            'tag' => $this->extractTagName($first),
            'description' => $this->ensureUtf8(implode("\n", $lines)),
        ];
    }

    /**
     * `@desc 消耗管理` → `消耗管理`；`@author ...` 等非业务标签丢弃。
     *
     * @return null|string null 表示该行不进入模块名/描述
     */
    private function stripDocAnnotationPrefix(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }
        if (preg_match('/^@(?:desc|description|summary)\s+(.+)$/iu', $line, $m)) {
            return trim($m[1]);
        }
        // @author / @link 等不参与模块名
        if (str_starts_with($line, '@')) {
            return null;
        }

        return $line;
    }

    /**
     * 「需求管理 接口（gen:crud 生成）：…」→ 需求管理
     */
    private function extractTagName(string $first): string
    {
        $first = trim($first);
        // 兜底：若上游未剥注解前缀
        if (preg_match('/^@(?:desc|description|summary)\s+(.+)$/iu', $first, $m)) {
            $first = trim($m[1]);
        }
        if ($first === '') {
            return '';
        }
        // 优先按中文分隔；半角 : 仅在「接口/（」都不存在时使用，避免误切 gen:crud
        foreach (['接口', '：', '（'] as $sep) {
            $pos = mb_strpos($first, $sep, 0, 'UTF-8');
            if ($pos !== false && $pos > 0) {
                return $this->trimModulePunctuation(mb_substr($first, 0, $pos, 'UTF-8'));
            }
        }
        foreach (['(', ':'] as $sep) {
            $pos = mb_strpos($first, $sep, 0, 'UTF-8');
            if ($pos !== false && $pos > 0) {
                return $this->trimModulePunctuation(mb_substr($first, 0, $pos, 'UTF-8'));
            }
        }

        return $this->trimModulePunctuation($first);
    }

    /**
     * 目录名：类注释优先（需求管理），否则回退 Controller 资源名（AccountDemand）。
     * 「需求管理.」「需求管理 V2」→「需求管理」
     */
    private function normalizeModuleFolder(string $tag, string $resourceFallback): string
    {
        $tag = $this->trimModulePunctuation($tag);
        // 去掉末尾版本后缀，保证 v1/v2 落在同一业务目录名下
        $stripped = preg_replace('/\s+V?\d+$/iu', '', $tag);
        if (is_string($stripped)) {
            $tag = $this->trimModulePunctuation($stripped);
        }

        return $tag !== '' ? $tag : $resourceFallback;
    }

    private function trimModulePunctuation(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return trim(preg_replace('/[.\。;；:：]+$/u', '', $value) ?? $value);
    }

    private function isFileHeaderLine(string $line): bool
    {
        return (bool) preg_match(
            '/^(This file is part of|@link|@document|@contact|@license)\b/i',
            $line
        );
    }

    /**
     * 仅清洗非法字节，绝不把合法 UTF-8 当 GBK/Latin1 再转码（否则会双重编码乱码）。
     */
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
        if ($value === '') {
            return '';
        }

        // 去掉控制符（保留 \n \r \t）；不用 /u 以免无效序列导致 preg 失败
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
    }

    /**
     * @return list<array{
     *   name: string,
     *   has_id: bool,
     *   form_request: ?string,
     *   resource_class: ?string,
     *   is_collection: bool,
     *   summary: string,
     *   description: string,
     *   body: string
     * }>
     */
    private function parsePublicMethods(string $code): array
    {
        $methods = [];
        // 不把 docblock 放进同一正则：可选 /\*\*.*?\*/ 会回溯吞掉文件头注释
        if (! preg_match_all(
            '#public\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)[^{]*\{#s',
            $code,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($matches as $i => $match) {
            $name = $match[1][0];
            $params = $match[2][0];
            $fnOffset = (int) $match[0][1];
            $start = $fnOffset + strlen($match[0][0]);
            $end = isset($matches[$i + 1]) ? (int) $matches[$i + 1][0][1] : strlen($code);
            $body = substr($code, $start, max(0, $end - $start));
            $docRaw = $this->docblockBefore($code, $fnOffset);

            $doc = $this->parseMethodDoc($docRaw);
            $formRequest = $this->paramsFormRequest($params) ?? $doc['request'];
            $resourceClass = $this->extractResourceClass($body, $doc['resource']);
            $isCollection = $this->isCollectionReturn($body, $doc['return']);

            $methods[] = [
                'name' => $name,
                'has_id' => $this->paramsSuggestId($params),
                'form_request' => $formRequest,
                'resource_class' => $resourceClass,
                'is_collection' => $isCollection,
                'summary' => $this->ensureUtf8($doc['summary']),
                'description' => $this->ensureUtf8($doc['description']),
                'body' => $body,
            ];
        }

        return $methods;
    }

    /**
     * 取符号正上方紧邻的 docblock（中间只允许空白与 #[Attr]）。
     * 不用可回溯的星号注释正则，避免吞掉文件头或上一个方法的注释。
     */
    private function docblockBefore(string $code, int $offset): string
    {
        $before = substr($code, 0, $offset);
        $cursor = rtrim($before);
        // 剥掉尾部属性
        while ($cursor !== '' && preg_match('/#\[[^\]]*\]\s*$/', $cursor)) {
            $cursor = rtrim((string) preg_replace('/#\[[^\]]*\]\s*$/', '', $cursor));
        }
        if ($cursor === '' || ! str_ends_with($cursor, '*/')) {
            return '';
        }

        $closePos = strrpos($cursor, '*/');
        if ($closePos === false) {
            return '';
        }
        $openPos = strrpos(substr($cursor, 0, $closePos), '/**');
        if ($openPos === false) {
            return '';
        }

        $doc = substr($cursor, $openPos, $closePos + 2 - $openPos);
        // 防御：doc 内不应再包含另一个完整方法签名（说明跨块了）
        if (preg_match('/\bfunction\s+[A-Za-z_]/', $doc)) {
            return '';
        }

        return $doc;
    }

    /**
     * @return array{summary: string, description: string, request: ?string, resource: ?string, return: string}
     */
    private function parseMethodDoc(string $docRaw): array
    {
        if ($docRaw === '' || ! str_contains($docRaw, '/**')) {
            return ['summary' => '', 'description' => '', 'request' => null, 'resource' => null, 'return' => ''];
        }
        if (! preg_match('#/\*\*(.*?)\*/#s', $docRaw, $m)) {
            return ['summary' => '', 'description' => '', 'request' => null, 'resource' => null, 'return' => ''];
        }

        $lines = $this->docLines($m[1]);
        $summary = '';
        $desc = [];
        $request = null;
        $resource = null;
        $return = '';

        foreach ($lines as $line) {
            if (preg_match('/^@(?:desc|description|summary)\s+(.+)$/iu', $line, $sm)) {
                if ($summary === '') {
                    $summary = trim($sm[1]);
                } else {
                    $desc[] = trim($sm[1]);
                }
                continue;
            }
            if (preg_match('/^@param\s+(\S+)/', $line, $pm)) {
                $type = ltrim($pm[1], '\\');
                if (str_ends_with($type, 'Request') && $type !== 'Request') {
                    $request = $type;
                }
                continue;
            }
            if (preg_match('/^@return\s+(\S+)/', $line, $rm)) {
                $return = ltrim($rm[1], '\\');
                if (str_ends_with($return, 'Resource')) {
                    $resource = $return;
                }
                continue;
            }
            if (str_starts_with($line, '@')) {
                continue;
            }
            if ($summary === '') {
                $summary = $line;
            } else {
                $desc[] = $line;
            }
        }

        return [
            'summary' => $summary,
            'description' => implode("\n", $desc),
            'request' => $request,
            'resource' => $resource,
            'return' => $return,
        ];
    }

    /**
     * @return list<string>
     */
    private function docLines(string $docBody): array
    {
        // 不能用 \R：在非 UTF-8 模式下会把多字节汉字中的 0x85 当成 NEL 切断（如「腾」= e8 85 be）
        $lines = preg_split('/\r\n|\n|\r/', $docBody) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = preg_replace('/^\s*\*\s?/', '', $line) ?? $line;
            $line = trim($line);
            if ($line === '' || $line === '/' || $line === '*') {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    private function paramsSuggestId(string $params): bool
    {
        $params = trim($params);
        if ($params === '') {
            return false;
        }
        if (preg_match('/\$id\b/', $params)) {
            return true;
        }
        foreach (preg_split('/,/', $params) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (! preg_match('/^(?:\??\\\\?)?([A-Za-z_][\w\\\\]*)\s+\$/', $part, $m)) {
                continue;
            }
            $type = ltrim($m[1], '\\');
            if (str_ends_with($type, 'Request') || str_ends_with($type, 'RequestInterface')) {
                continue;
            }
            if (in_array(strtolower($type), ['int', 'string', 'float', 'bool', 'array', 'mixed', 'object'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function paramsFormRequest(string $params): ?string
    {
        if (preg_match('/([A-Za-z_][\w\\\\]*Request)\s+\$/', $params, $m)) {
            $type = ltrim($m[1], '\\');
            if ($type === 'Request' || $type === 'ServerRequestInterface') {
                return null;
            }

            return $type;
        }

        return null;
    }

    private function extractResourceClass(string $body, ?string $fromDoc): ?string
    {
        if (preg_match('/([A-Za-z_][\w\\\\]*Resource)::class/', $body, $m)) {
            return ltrim($m[1], '\\');
        }

        return $fromDoc;
    }

    private function isCollectionReturn(string $body, string $returnType): bool
    {
        if (stripos($returnType, 'Collection') !== false) {
            return true;
        }

        return (bool) preg_match('/\$this->collection\s*\(/', $body);
    }

    /**
     * @param array{tag: string, description: string} $classDoc
     * @param array{class: string, resource: string, version: string, file: string} $meta
     * @param array<string, mixed> $methodInfo
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $gateway
     */
    private function resolveGatewayFolder(array $gateway): string
    {
        $folder = trim((string) ($gateway['folder'] ?? ''));
        if ($folder !== '') {
            return $folder;
        }
        // 兼容旧 tag_prefix / 从 namespace 推断：...Controller\Api → Api
        $tagPrefix = trim((string) ($gateway['tag_prefix'] ?? ''), '/');
        if ($tagPrefix !== '') {
            return $tagPrefix;
        }
        $namespace = (string) ($gateway['namespace'] ?? '');
        if (preg_match('/\\\\Controller\\\\([^\\\\]+)$/', $namespace, $m)) {
            return $m[1];
        }

        return 'Api';
    }

    private function buildOperation(
        string $prefix,
        string $resourceSeg,
        string $tag,
        string $apifoxFolder,
        array $classDoc,
        array $meta,
        array $methodInfo,
        bool $security,
        string $envelopeStyle = 'formatted'
    ): array {
        $name = (string) $methodInfo['name'];
        $hasIdParam = (bool) $methodInfo['has_id'];
        $formRequestShort = $methodInfo['form_request'] !== null ? (string) $methodInfo['form_request'] : null;
        $summary = (string) $methodInfo['summary'];
        $methodDesc = (string) $methodInfo['description'];
        $version = $meta['version'];
        $class = $meta['class'];

        if (isset(self::REST_ACTIONS[$name])) {
            [$http, $needId] = self::REST_ACTIONS[$name];
            $path = $needId
                ? sprintf('%s/%s/{id}', $prefix, $resourceSeg)
                : sprintf('%s/%s', $prefix, $resourceSeg);
        } else {
            $action = $this->actionSegment($name);
            $path = $hasIdParam
                ? sprintf('%s/%s/{id}/%s', $prefix, $resourceSeg, $action)
                : sprintf('%s/%s/%s', $prefix, $resourceSeg, $action);
            $http = $formRequestShort !== null ? 'post' : 'get';
            if (str_starts_with($name, 'update') || str_starts_with($name, 'edit')) {
                $http = 'put';
                if (! $hasIdParam) {
                    $path = sprintf('%s/%s/{id}/%s', $prefix, $resourceSeg, $action);
                }
            } elseif (str_starts_with($name, 'delete') || str_starts_with($name, 'destroy') || str_starts_with($name, 'remove')) {
                $http = 'delete';
            }
        }

        // 公共 header（X-API-Version 等）由 OpenApiGenerator 按 common_headers 以 $ref 注入
        $parameters = [];
        if (str_contains($path, '{id}')) {
            $parameters[] = [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        $requestBody = null;
        $requestSchema = null;
        if ($formRequestShort !== null) {
            $requestFile = $this->resolveRequestFile($class, $formRequestShort);
            if ($requestFile !== null) {
                $requestSchema = $this->schemas->fromRequestFile($requestFile);
            }
        }

        $resourceName = $meta['resource'];
        $isCollection = (bool) $methodInfo['is_collection']
            || $name === 'index'
            || (bool) preg_match('/\$this->collection\s*\(/', (string) $methodInfo['body']);

        $dataSchema = ['type' => 'object'];
        $resourceIncludes = [];
        $resourceShort = $methodInfo['resource_class'] !== null ? (string) $methodInfo['resource_class'] : null;
        if ($resourceShort === null) {
            // 约定：AccountDemandController → AccountDemandResource
            $resourceShort = $meta['resource'] . 'Resource';
        }
        $resourceFile = $this->resolveResourceFile($class, $resourceShort);
        if ($resourceFile !== null) {
            $fqcn = $this->fqcnFromAppFile($resourceFile)
                ?? (str_contains($resourceShort, '\\')
                    ? $resourceShort
                    : str_replace('\\Controller\\', '\\Resource\\', substr($class, 0, (int) strrpos($class, '\\'))) . '\\' . $resourceShort);
            $resourceSchema = $this->schemas->fromResourceFile($resourceFile, $fqcn, $isCollection);
            $dataSchema = $resourceSchema['schema'];
            $resourceIncludes = $resourceSchema['includes'];
        }

        // GET 列表：先业务筛选，page / pageSize / include 统一放参数末尾
        if ($http === 'get' && ($isCollection || $name === 'index')) {
            $filterFile = $this->resolveFilterFile($resourceName);
            $filterSchema = $this->schemas->fromFilterFile($filterFile ?? '', false);
            $queryProperties = array_merge($filterSchema['properties'], $requestSchema['properties'] ?? []);
            $queryRequired = $requestSchema['required'] ?? [];
            foreach ($queryProperties as $field => $prop) {
                if (in_array($field, ['page', 'pageSize', 'include'], true)) {
                    continue;
                }
                $parameters[] = [
                    'name' => $field,
                    'in' => 'query',
                    'required' => in_array($field, $queryRequired, true),
                    'description' => $prop['description'] ?? ($prop['title'] ?? ''),
                    'schema' => array_diff_key($prop, ['description' => 1, 'title' => 1]),
                ];
            }
            foreach ($this->schemas->paginationQueryParams() as $field => $prop) {
                $parameters[] = [
                    'name' => $field,
                    'in' => 'query',
                    'required' => false,
                    'description' => $prop['description'] ?? ($prop['title'] ?? ''),
                    'schema' => array_diff_key($prop, ['description' => 1, 'title' => 1]),
                ];
            }
        } elseif ($requestSchema !== null && $http === 'get') {
            foreach ($requestSchema['properties'] as $field => $prop) {
                if (in_array($field, ['page', 'pageSize', 'include'], true)) {
                    continue;
                }
                $parameters[] = [
                    'name' => $field,
                    'in' => 'query',
                    'required' => in_array($field, $requestSchema['required'], true),
                    'description' => $prop['description'] ?? ($prop['title'] ?? ''),
                    'schema' => array_diff_key($prop, ['description' => 1, 'title' => 1]),
                ];
            }
        }

        // page / pageSize 之后：include 始终在最后
        if ($resourceIncludes !== [] && in_array($http, ['get', 'post', 'put', 'patch'], true)) {
            $parameters[] = $this->buildIncludeParameter($resourceIncludes);
        }

        if (in_array($http, ['post', 'put', 'patch'], true)) {
            $bodySchema = $this->schemas->forWriteBody($requestSchema, $resourceName);
            if ($bodySchema['properties'] !== []) {
                $schema = [
                    'type' => 'object',
                    'properties' => $bodySchema['properties'],
                ];
                if ($bodySchema['required'] !== []) {
                    $schema['required'] = $bodySchema['required'];
                }
                $requestBody = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $schema,
                        ],
                    ],
                ];
            }
        }

        $responseSchema = $this->schemas->wrapSuccessEnvelope($dataSchema, $isCollection, $envelopeStyle);

        $descriptionParts = array_filter([
            $methodDesc !== '' ? $methodDesc : null,
            $classDoc['description'] !== '' ? '模块：' . $classDoc['description'] : null,
            $formRequestShort ? '请求：' . $formRequestShort : null,
            $resourceShort ? '响应：' . $resourceShort . ($isCollection ? '（列表）' : '') : null,
            $resourceIncludes !== [] ? '关联：?include=' . implode(',', $resourceIncludes) : null,
        ]);

        return [
            'version' => $version,
            'path' => $path === '' ? '/' : $path,
            'method' => $http,
            'operationId' => sprintf(
                '%s_%s_%s_%s',
                trim(str_replace('/', '_', $prefix), '_'),
                $resourceSeg,
                $version,
                $name
            ),
            'summary' => $this->ensureUtf8($summary !== '' ? $summary : sprintf('%s@%s', $tag, $name)),
            'description' => $this->ensureUtf8(implode("\n", $descriptionParts)),
            // tags：给 HTML/Swagger 分组用，不要带 /（会被拆成空的父级目录）
            // x-apifox-folder：Apifox 客户端真正的多级目录
            'tags' => [$this->ensureUtf8($version . ' · ' . $tag)],
            'x-apifox-folder' => $apifoxFolder,
            'parameters' => $parameters,
            'requestBody' => $requestBody,
            'responses' => [
                '200' => [
                    'description' => '成功',
                    'content' => [
                        'application/json' => [
                            'schema' => $responseSchema,
                        ],
                    ],
                ],
            ],
            'security' => $security ? [['bearerAuth' => []]] : [],
        ];
    }

    /**
     * @param list<string> $includes
     * @return array<string, mixed>
     */
    private function buildIncludeParameter(array $includes): array
    {
        $example = implode(',', $includes);

        return [
            'name' => 'include',
            'in' => 'query',
            'required' => false,
            'description' => '预加载关联，多个用英文逗号分隔。可选：' . implode(', ', $includes),
            'example' => $example,
            'schema' => [
                'type' => 'string',
                'example' => $example,
            ],
        ];
    }

    /**
     * 空 Vn Controller 继承父版方法时，合并父类公开 action（子类同名优先）。
     *
     * @param list<array<string, mixed>> $methods
     * @return list<array<string, mixed>>
     */
    private function mergeInheritedMethods(string $class, string $code, array $methods): array
    {
        $byName = [];
        foreach ($methods as $method) {
            $byName[(string) $method['name']] = $method;
        }

        $parentFile = $this->resolveParentControllerFile($code, $class);
        $guard = 0;
        while ($parentFile !== null && $guard++ < 8) {
            $parentCode = $this->ensureUtf8((string) file_get_contents($parentFile));
            $parentClass = $this->classFromAppFile($parentFile) ?? '';
            foreach ($this->parsePublicMethods($parentCode) as $parentMethod) {
                $name = (string) $parentMethod['name'];
                if (! isset($byName[$name])) {
                    $byName[$name] = $parentMethod;
                }
            }
            $parentFile = $parentClass !== ''
                ? $this->resolveParentControllerFile($parentCode, $parentClass)
                : null;
        }

        return array_values($byName);
    }

    private function resolveParentControllerFile(string $code, string $class): ?string
    {
        if (! preg_match('/\bextends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $code, $m)) {
            return null;
        }

        $fqcn = $this->resolveImportedClass($code, $m[1], $class);
        if ($fqcn === null || ! str_starts_with($fqcn, 'App\\Controller\\')) {
            return null;
        }

        $file = $this->appFileFromFqcn($fqcn);

        return $file !== null && is_file($file) ? $file : null;
    }

    private function resolveImportedClass(string $code, string $shortOrFqcn, string $currentClass): ?string
    {
        $name = ltrim($shortOrFqcn, '\\');
        if (str_contains($name, '\\')) {
            return $name;
        }

        if (preg_match(
            '/\buse\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+as\s+' . preg_quote($name, '/') . '\s*;/',
            $code,
            $m
        )) {
            return ltrim($m[1], '\\');
        }

        if (preg_match(
            '/\buse\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\' . preg_quote($name, '/') . ')\s*;/',
            $code,
            $m
        )) {
            return ltrim($m[1], '\\');
        }

        $ns = substr($currentClass, 0, (int) strrpos($currentClass, '\\'));

        return $ns . '\\' . $name;
    }

    private function resolveRequestFile(string $controllerClass, string $short): ?string
    {
        return $this->resolveVersionedAppFile($controllerClass, $short, 'Request');
    }

    private function resolveFilterFile(string $resource): ?string
    {
        if ($resource === '' || ! defined('BASE_PATH')) {
            return null;
        }
        $file = BASE_PATH . '/app/Model/Filters/' . $resource . 'Filter.php';

        return is_file($file) ? $file : null;
    }

    private function resolveResourceFile(string $controllerClass, string $short): ?string
    {
        return $this->resolveVersionedAppFile($controllerClass, $short, 'Resource');
    }

    /**
     * 按 Controller 命名空间解析 Request/Resource；Vn 不存在时回退到 V(n-1)…V1。
     */
    private function resolveVersionedAppFile(string $controllerClass, string $short, string $segment): ?string
    {
        $short = ltrim($short, '\\');
        $base = str_contains($short, '\\') ? substr($short, strrpos($short, '\\') + 1) : $short;
        $ns = substr($controllerClass, 0, (int) strrpos($controllerClass, '\\'));
        $namespaces = [$ns];

        if (preg_match('/^(.*\\\\)V(\d+)$/', $ns, $m)) {
            for ($i = (int) $m[2] - 1; $i >= 1; --$i) {
                $namespaces[] = $m[1] . 'V' . $i;
            }
        }

        foreach ($namespaces as $ctrlNs) {
            $targetNs = str_replace('\\Controller\\', '\\' . $segment . '\\', $ctrlNs);
            $file = BASE_PATH . '/app/' . substr(str_replace('\\', '/', $targetNs), 4) . '/' . $base . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        // 显式 FQCN
        if (str_contains($short, '\\')) {
            $file = $this->appFileFromFqcn($short);

            return $file !== null && is_file($file) ? $file : null;
        }

        return null;
    }

    private function fqcnFromAppFile(string $file): ?string
    {
        if (! defined('BASE_PATH')) {
            return null;
        }
        $prefix = BASE_PATH . '/app/';
        $real = realpath($file) ?: $file;
        if (! str_starts_with($real, $prefix) || ! str_ends_with($real, '.php')) {
            return null;
        }

        return 'App\\' . str_replace('/', '\\', substr($real, strlen($prefix), -4));
    }

    private function classFromAppFile(string $file): ?string
    {
        return $this->fqcnFromAppFile($file);
    }

    private function appFileFromFqcn(string $fqcn): ?string
    {
        if (! defined('BASE_PATH') || ! str_starts_with($fqcn, 'App\\')) {
            return null;
        }

        return BASE_PATH . '/app/' . str_replace('\\', '/', substr($fqcn, 4)) . '.php';
    }

    private function resourceSegment(string $studly, string $case): string
    {
        return match ($case) {
            'camel' => lcfirst($studly),
            'kebab' => Str::kebab($studly),
            'kebab_plural' => Str::kebab(Str::pluralStudly($studly)),
            'snake' => Str::snake($studly),
            default => Str::plural(Str::snake($studly)),
        };
    }

    private function actionSegment(string $method): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $method) ?? $method);
    }
}
