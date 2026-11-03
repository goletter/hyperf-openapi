<?php

declare(strict_types=1);

namespace Goletter\OpenApi\Generator;

use Goletter\OpenApi\Scanner\DispatcherControllerScanner;
use Hyperf\Contract\ConfigInterface;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * 按 API 版本（v1/v2/v3）分别生成 OpenAPI 3，可导入 Apifox。
 */
final class OpenApiGenerator
{
    private DispatcherControllerScanner $scanner;

    public function __construct(private ConfigInterface $config)
    {
        $this->scanner = new DispatcherControllerScanner();
    }

    /**
     * 发现可用版本（配置优先，否则扫目录）。
     *
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    public function getScanDebug(): array
    {
        return $this->scanner->getDebug();
    }

    public function discoverVersions(): array
    {
        $found = [];
        foreach ((array) $this->config->get('openapi.gateways', []) as $gateway) {
            $basePath = (string) ($gateway['path'] ?? '');
            if ($basePath === '' || ! is_dir($basePath)) {
                continue;
            }
            foreach (scandir($basePath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (preg_match('/^V(\d+)$/i', $entry, $m) && is_dir($basePath . DIRECTORY_SEPARATOR . $entry)) {
                    $found[] = 'v' . $m[1];
                }
            }
        }
        $found = array_values(array_unique($found));

        $configured = (array) $this->config->get('openapi.versions', []);
        if ($configured !== []) {
            $normalized = array_map(fn ($v) => $this->normalizeVersion((string) $v), $configured);
            // 配置 ∩ 实际存在的版本目录；若交集为空则退回目录扫描结果
            $intersect = array_values(array_filter(
                $normalized,
                static fn (string $v): bool => in_array($v, $found, true)
            ));

            return $intersect !== [] ? $intersect : $found;
        }

        usort($found, static function (string $a, string $b): int {
            return ((int) substr($b, 1)) <=> ((int) substr($a, 1));
        });

        return $found;
    }

    /**
     * 生成单个版本文档。
     *
     * @return array<string, mixed>
     */
    public function generate(?string $version = null): array
    {
        if (! $this->config->get('openapi.enable', true)) {
            throw new RuntimeException('openapi.enable=false，已禁用生成');
        }

        $version = $version !== null && $version !== ''
            ? $this->normalizeVersion($version)
            : null;

        $gateways = $this->gatewaysForVersion($version);
        if ($gateways === []) {
            throw new RuntimeException(
                $version === null
                    ? 'openapi.gateways 为空'
                    : sprintf('版本 %s 无可用网关/Controller', $version)
            );
        }

        $resourceCase = (string) $this->config->get('openapi.resource_case', 'camel');
        $withSecurity = (bool) $this->config->get('openapi.operation_security', true);
        $envelopeStyle = (string) $this->config->get('openapi.response_envelope', 'formatted');
        $operations = $this->scanner->scan($gateways, $resourceCase, $withSecurity, $envelopeStyle);

        if ($version !== null) {
            $operations = array_values(array_filter(
                $operations,
                static fn (array $op): bool => ($op['version'] ?? '') === $version
            ));
        }

        return $this->buildDocument($operations, $version);
    }

    /**
     * 生成全部版本。
     *
     * @return array<string, array<string, mixed>> version => doc
     */
    public function generateAll(): array
    {
        $versions = $this->discoverVersions();
        if ($versions === []) {
            throw new RuntimeException('未发现任何 API 版本目录（如 V1/V2）');
        }

        $docs = [];
        foreach ($versions as $version) {
            $docs[$version] = $this->generate($version);
        }

        return $docs;
    }

    /**
     * 写入一个或全部版本文件。
     *
     * @return list<array{version: string, json:?string, yaml:?string, paths: int}>
     */
    public function write(?string $version = null): array
    {
        $docs = $version !== null && $version !== ''
            ? [$this->normalizeVersion($version) => $this->generate($version)]
            : $this->generateAll();

        $result = [];
        $indexed = [];
        foreach ($docs as $ver => $doc) {
            $pathCount = $this->countPaths($doc['paths'] ?? null);
            // 目录尚无 Controller 的版本跳过落盘，避免空文件干扰 Apifox
            if ($pathCount === 0) {
                continue;
            }

            $jsonPath = $this->resolveOutputPath('json', $ver);
            $yamlPath = $this->resolveOutputPath('yaml', $ver);
            $written = ['version' => $ver, 'json' => null, 'yaml' => null, 'paths' => $pathCount];

            if (is_string($jsonPath) && $jsonPath !== '') {
                $this->ensureDirectory($jsonPath);
                file_put_contents($jsonPath, $this->encodeJson($doc));
                $written['json'] = $jsonPath;
            }
            if (is_string($yamlPath) && $yamlPath !== '') {
                try {
                    $this->ensureDirectory($yamlPath);
                    file_put_contents($yamlPath, $this->encodeYaml($doc));
                    $written['yaml'] = $yamlPath;
                } catch (Throwable) {
                    // YAML 可选；缺 symfony/yaml 时仍保留 JSON
                }
            }
            $result[] = $written;
            $indexed[$ver] = $doc;
        }

        if ($indexed === []) {
            throw new RuntimeException('未生成任何版本文档：请确认 Controller 存在于配置的 versions / Vn 目录');
        }

        $this->writeIndex($indexed);
        $this->writeApifoxEnvironments();
        // 全量合并文档：一次导入即可得到 v1/Api、v1/Admin、v2/... 目录树
        if (count($indexed) > 1 || $version === null || $version === '') {
            $combinedPath = $this->writeCombined($indexed);
            if ($combinedPath !== null) {
                $result[] = [
                    'version' => 'all',
                    'json' => $combinedPath,
                    'yaml' => null,
                    'paths' => $this->countPaths(
                        json_decode((string) file_get_contents($combinedPath), true)['paths'] ?? null
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * 合并各版本文档 → openapi.json，目录为 {version}/{folder}/{模块}。
     * 真实请求 path 不含版本（/api/account_demands）；多版本同 path+method 时
     * OpenAPI 不能重复 key，故导出为 `/api/account_demands#v1`（# 后为版本锚点，
     * HTTP 不会把 fragment 发给服务端）。单版本文档 openapi.v1.json 仍是纯真实 path。
     *
     * @param array<string, array<string, mixed>> $docs
     */
    private function writeCombined(array $docs): ?string
    {
        if ($docs === []) {
            return null;
        }

        $mergedPaths = [];
        $tags = [];
        $mergedParameters = [];
        $mergedSecuritySchemes = [];
        $mergedSecurity = [];
        $multiVersion = count($docs) > 1;
        foreach ($docs as $ver => $doc) {
            $ver = (string) $ver;
            $docParams = (array) (($doc['components']['parameters'] ?? []) ?: []);
            foreach ($docParams as $paramName => $paramDef) {
                if (! is_string($paramName) || ! is_array($paramDef)) {
                    continue;
                }
                // 多版本时 X-API-Version 各文件 enum 不同，拆成独立 component
                if ($multiVersion && $paramName === 'X-API-Version') {
                    $mergedParameters['X-API-Version.' . $ver] = $paramDef;
                } elseif (! isset($mergedParameters[$paramName])) {
                    $mergedParameters[$paramName] = $paramDef;
                }
            }
            foreach ((array) (($doc['components']['securitySchemes'] ?? []) ?: []) as $schemeName => $schemeDef) {
                if (is_string($schemeName) && is_array($schemeDef) && ! isset($mergedSecuritySchemes[$schemeName])) {
                    $mergedSecuritySchemes[$schemeName] = $schemeDef;
                }
            }
            if ($mergedSecurity === [] && ! empty($doc['security']) && is_array($doc['security'])) {
                $mergedSecurity = $doc['security'];
            }

            foreach ((array) ($doc['paths'] ?? []) as $path => $methods) {
                if (! is_array($methods)) {
                    continue;
                }
                // 多版本：path#v1 保证 v1/v2 目录都保留，且请求 path 仍是 # 前的真实地址
                $exportPath = $multiVersion
                    ? ((string) $path . '#' . $ver)
                    : (string) $path;
                foreach ($methods as $method => $operation) {
                    if (! is_array($operation)) {
                        continue;
                    }
                    if ($multiVersion) {
                        $operation['parameters'] = $this->rewriteApiVersionParameterRefs(
                            (array) ($operation['parameters'] ?? []),
                            $ver
                        );
                    }
                    $mergedPaths[$exportPath][$method] = $operation;
                    foreach ((array) ($operation['tags'] ?? []) as $tag) {
                        if (is_string($tag) && $tag !== '') {
                            $tags[$tag] = ['name' => $tag];
                        }
                    }
                }
            }
        }
        if ($mergedPaths === []) {
            return null;
        }
        ksort($mergedPaths);

        $first = reset($docs) ?: [];
        $combined = [
            'openapi' => $first['openapi'] ?? '3.0.3',
            'info' => [
                'title' => (string) ($this->config->get('openapi.info.title') ?? 'Goletter API'),
                'version' => 'all',
                'description' => (string) ($this->config->get('openapi.info.description') ?? '按版本自动扫描 Controller，可导入 Apifox'),
            ],
            'servers' => $first['servers'] ?? $this->resolveServers(),
            'tags' => array_values($tags),
            'paths' => $mergedPaths,
        ];
        $components = [];
        if ($mergedParameters !== []) {
            $components['parameters'] = $mergedParameters;
        }
        if ($mergedSecuritySchemes !== []) {
            $components['securitySchemes'] = $mergedSecuritySchemes;
        }
        if ($components !== []) {
            $combined['components'] = $components;
        }
        if ($mergedSecurity !== []) {
            $combined['security'] = $mergedSecurity;
        }

        $dir = (string) $this->config->get('openapi.output.dir', BASE_PATH . '/storage/openapi');
        $path = rtrim($dir, '/') . '/openapi.json';
        $this->ensureDirectory($path);
        file_put_contents($path, $this->encodeJson($combined));

        return $path;
    }

    /**
     * 写出 Apifox 可导入的环境文件（带模块前置 URL / baseUrl）。
     * OpenAPI 的 servers  alone 在部分导入路径下不会写入模块前置 URL。
     */
    public function writeApifoxEnvironments(): string
    {
        $dir = (string) $this->config->get('openapi.output.dir', BASE_PATH . '/storage/openapi');
        $path = rtrim($dir, '/') . '/apifox.environments.json';
        $this->ensureDirectory($path);

        $environments = [];
        foreach ($this->resolveServers() as $i => $server) {
            $url = (string) $server['url'];
            $name = (string) ($server['description'] ?: ('环境' . ($i + 1)));
            $parts = parse_url($url) ?: [];
            $protocol = (string) ($parts['scheme'] ?? 'http');
            $host = (string) ($parts['host'] ?? '127.0.0.1');
            if (isset($parts['port'])) {
                $host .= ':' . $parts['port'];
            }
            $environments[] = [
                'name' => $name,
                'baseUrl' => $url,
                'servers' => [
                    [
                        'id' => 'default',
                        'name' => '默认服务',
                        'url' => $url,
                        'protocol' => $protocol,
                        'host' => $host,
                    ],
                ],
                'variables' => [
                    [
                        'name' => 'BASE_URL',
                        'value' => $url,
                        'type' => 'string',
                        'description' => '前置 URL',
                    ],
                ],
            ];
        }

        $payload = [
            'type' => 'apifox-environments',
            'version' => '1.0',
            'hint' => 'Apifox：环境管理 → 右上角 … → 导入；或导入 OpenAPI 时勾选「导入 Servers 为环境」且「导入到 → 创建新模块」',
            'environments' => $environments,
        ];
        file_put_contents($path, $this->encodeJson($payload));

        return $path;
    }

    public function toJson(?string $version = null): string
    {
        if ($version === null || $version === '') {
            throw new RuntimeException('请指定 API 版本，例如 v1');
        }

        return $this->encodeJson($this->generate($version));
    }

    public function toYaml(?string $version = null): string
    {
        if ($version === null || $version === '') {
            throw new RuntimeException('请指定 API 版本，例如 v1');
        }

        return $this->encodeYaml($this->generate($version));
    }

    public function toJsonIndex(): string
    {
        return $this->encodeJson($this->indexPayload());
    }

    /**
     * @return array{versions: list<string>, files: array<string, array{json?:string, yaml?:string}>}
     */
    public function indexPayload(): array
    {
        $versions = $this->discoverVersions();
        $files = [];
        foreach ($versions as $ver) {
            $entry = [];
            $json = $this->resolveOutputPath('json', $ver);
            $yaml = $this->resolveOutputPath('yaml', $ver);
            if ($json) {
                $entry['json'] = $this->publicUrlForVersion($ver, 'json');
            }
            if ($yaml) {
                $entry['yaml'] = $this->publicUrlForVersion($ver, 'yaml');
            }
            $files[$ver] = $entry;
        }

        return [
            'versions' => $versions,
            'default' => $versions[0] ?? null,
            'files' => $files,
            'hint' => 'Apifox 请分别导入各版本 JSON，或使用 /openapi/{version}.json',
        ];
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
     * @return list<array<string, mixed>>
     */
    private function gatewaysForVersion(?string $version): array
    {
        $gateways = (array) $this->config->get('openapi.gateways', []);
        $result = [];

        foreach ($gateways as $gateway) {
            if (! is_array($gateway)) {
                continue;
            }
            $gateway = $gateway;
            if ($version !== null) {
                $gateway['versions'] = [$version];
            } elseif (! isset($gateway['versions']) || $gateway['versions'] === [] || $gateway['versions'] === null) {
                $gateway['versions'] = $this->discoverVersions();
            }
            $result[] = $gateway;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    private function buildDocument(array $operations, ?string $version): array
    {
        $commonParams = $this->buildCommonHeaderComponents($version);
        $commonRefs = $this->commonHeaderParameterRefs(array_keys($commonParams));

        $paths = [];
        foreach ($operations as $op) {
            $path = (string) $op['path'];
            $method = strtolower((string) $op['method']);
            $paths[$path] ??= [];
            $item = [
                'tags' => $op['tags'],
                'summary' => $op['summary'],
                'description' => $op['description'],
                'operationId' => $op['operationId'],
                'parameters' => array_merge(
                    $commonRefs,
                    $this->stripDuplicateCommonHeaders((array) ($op['parameters'] ?? []), array_keys($commonParams))
                ),
            ];
            // Apifox 目录：v1/Api、v1/Admin（优先于 tags）
            if (! empty($op['x-apifox-folder'])) {
                $item['x-apifox-folder'] = $op['x-apifox-folder'];
            }
            // requestBody 放在 responses 前，便于 Apifox 识别 Body 字段
            if (! empty($op['requestBody'])) {
                $item['requestBody'] = $op['requestBody'];
            }
            $item['responses'] = $op['responses'];
            if (! empty($op['security'])) {
                $item['security'] = $op['security'];
            }
            $paths[$path][$method] = $item;
        }
        ksort($paths);

        $info = (array) $this->config->get('openapi.info', []);
        $title = (string) ($info['title'] ?? 'API');
        if ($version !== null) {
            $title = sprintf('%s (%s)', $title, strtoupper($version));
        }

        $doc = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $title,
                'version' => $version ?? (string) ($info['version'] ?? '1.0.0'),
                'description' => (string) ($info['description'] ?? '自动扫描 Controller 生成，可导入 Apifox'),
            ],
            'servers' => $this->resolveServers(),
            'paths' => $paths ?: new \stdClass(),
        ];

        $components = [];
        if ($commonParams !== []) {
            $components['parameters'] = $commonParams;
        }
        $schemes = (array) $this->config->get('openapi.security_schemes', []);
        if ($schemes !== []) {
            $components['securitySchemes'] = $schemes;
        }
        if ($components !== []) {
            $doc['components'] = $components;
        }
        $security = (array) $this->config->get('openapi.security', []);
        if ($security !== []) {
            $doc['security'] = $security;
        }

        return $doc;
    }

    /**
     * common_headers → components.parameters；分版本文档将 X-API-Version 写死为当前版本。
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildCommonHeaderComponents(?string $version): array
    {
        $headers = (array) $this->config->get('openapi.common_headers', []);
        if ($headers === []) {
            return [];
        }

        $parameters = [];
        foreach ($headers as $name => $meta) {
            if (! is_string($name) || $name === '' || ! is_array($meta)) {
                continue;
            }
            $type = (string) ($meta['type'] ?? 'string');
            if ($type === '') {
                $type = 'string';
            }
            $schema = ['type' => $type];
            $description = (string) ($meta['description'] ?? '');

            if ($name === 'X-API-Version' && $version !== null && $version !== '') {
                $schema['enum'] = [$version];
                $schema['default'] = $version;
                $schema['example'] = $version;
                $description = $description !== ''
                    ? $description
                    : sprintf('API 版本（本文件固定为 %s）', $version);
            } elseif (isset($meta['example'])) {
                $schema['example'] = $meta['example'];
            }

            $parameters[$name] = [
                'name' => $name,
                'in' => 'header',
                'required' => (bool) ($meta['required'] ?? false),
                'description' => $description,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /**
     * @param list<string> $names
     * @return list<array{$ref: string}>
     */
    private function commonHeaderParameterRefs(array $names): array
    {
        $refs = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $refs[] = ['$ref' => '#/components/parameters/' . $name];
        }

        return $refs;
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @param list<string> $commonNames
     * @return list<array<string, mixed>>
     */
    private function stripDuplicateCommonHeaders(array $parameters, array $commonNames): array
    {
        if ($commonNames === []) {
            return $parameters;
        }
        $set = array_fill_keys($commonNames, true);
        $out = [];
        foreach ($parameters as $param) {
            if (! is_array($param)) {
                continue;
            }
            if (isset($param['$ref']) && is_string($param['$ref'])) {
                foreach ($commonNames as $name) {
                    if ($param['$ref'] === '#/components/parameters/' . $name) {
                        continue 2;
                    }
                }
            }
            $name = (string) ($param['name'] ?? '');
            $in = (string) ($param['in'] ?? '');
            if ($name !== '' && $in === 'header' && isset($set[$name])) {
                continue;
            }
            $out[] = $param;
        }

        return $out;
    }

    /**
     * 合并多版本文档时，将 X-API-Version $ref 改写为带版本后缀的 component。
     *
     * @param list<array<string, mixed>> $parameters
     * @return list<array<string, mixed>>
     */
    private function rewriteApiVersionParameterRefs(array $parameters, string $version): array
    {
        $from = '#/components/parameters/X-API-Version';
        $to = '#/components/parameters/X-API-Version.' . $version;
        $out = [];
        foreach ($parameters as $param) {
            if (! is_array($param)) {
                continue;
            }
            if (($param['$ref'] ?? null) === $from) {
                $param['$ref'] = $to;
            }
            $out[] = $param;
        }

        return $out;
    }

    /**
     * Apifox 导入时靠 servers → 环境；description 用「开发环境」等标准名更易对齐。
     *
     * @return list<array{url: string, description: string}>
     */
    private function resolveServers(): array
    {
        $servers = [];
        $seen = [];
        foreach ((array) $this->config->get('openapi.servers', []) as $server) {
            if (! is_array($server)) {
                continue;
            }
            $url = $this->normalizeServerUrl((string) ($server['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $description = trim((string) ($server['description'] ?? ''));
            if ($description === '') {
                $description = '开发环境';
            }
            // 同名环境只保留第一条，避免三个相同 URL 干扰 Apifox
            if (isset($seen[$description])) {
                continue;
            }
            $seen[$description] = true;
            $servers[] = [
                'url' => $url,
                'description' => $description,
            ];
        }

        if ($servers === []) {
            $servers[] = [
                'url' => $this->defaultServerUrl(),
                'description' => '开发环境',
            ];
        }

        return $servers;
    }

    private function normalizeServerUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // env 占位未解析或空 APP_URL 时丢掉
        if (! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return rtrim($url, '/');
    }

    private function defaultServerUrl(): string
    {
        $port = (int) $this->config->get('server.servers.0.port', 8501);
        if ($port <= 0) {
            $port = 8501;
        }

        return sprintf('http://127.0.0.1:%d', $port);
    }

    private function resolveOutputPath(string $format, string $version): ?string
    {
        $dir = (string) $this->config->get('openapi.output.dir', BASE_PATH . '/storage/openapi');
        $pattern = (string) $this->config->get(
            $format === 'yaml' ? 'openapi.output.yaml_pattern' : 'openapi.output.json_pattern',
            $format === 'yaml' ? 'openapi.{version}.yaml' : 'openapi.{version}.json'
        );
        if ($pattern === '' || strtolower($pattern) === 'null') {
            return null;
        }

        return rtrim($dir, '/') . '/' . str_replace('{version}', $version, $pattern);
    }

    /**
     * @param array<string, array<string, mixed>> $docs
     */
    private function writeIndex(array $docs): void
    {
        $dir = (string) $this->config->get('openapi.output.dir', BASE_PATH . '/storage/openapi');
        $indexPath = rtrim($dir, '/') . '/index.json';
        $this->ensureDirectory($indexPath);

        $payload = [
            'versions' => array_keys($docs),
            'default' => array_key_first($docs),
            'generated_at' => date('c'),
            'files' => [],
        ];
        foreach ($docs as $ver => $doc) {
            $payload['files'][$ver] = [
                'json' => $this->relative($this->resolveOutputPath('json', $ver) ?? ''),
                'yaml' => $this->relative($this->resolveOutputPath('yaml', $ver) ?? ''),
                'paths' => $this->countPaths($doc['paths'] ?? null),
            ];
        }
        file_put_contents($indexPath, $this->encodeJson($payload));
    }

    private function countPaths(mixed $paths): int
    {
        if (is_array($paths)) {
            return count($paths);
        }
        if (is_object($paths)) {
            return count(get_object_vars($paths));
        }

        return 0;
    }

    private function publicUrlForVersion(string $version, string $format): string
    {
        $template = (string) $this->config->get(
            $format === 'yaml' ? 'openapi.route.yaml_path' : 'openapi.route.json_path',
            $format === 'yaml' ? '/openapi/{version}.yaml' : '/openapi/{version}.json'
        );

        return str_replace('{version}', $version, $template);
    }

    private function relative(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return str_starts_with($path, BASE_PATH . '/')
            ? substr($path, strlen(BASE_PATH) + 1)
            : $path;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function encodeJson(array $doc): string
    {
        $doc = $this->utf8ize($doc);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($doc, $flags);
        if ($json === false) {
            throw new RuntimeException('JSON 编码失败：' . json_last_error_msg());
        }

        return $json . "\n";
    }

    /**
     * 递归保证字符串为合法 UTF-8，避免 json_encode Malformed UTF-8。
     */
    private function utf8ize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? $this->utf8String($k) : $k;
                $out[$key] = $this->utf8ize($v);
            }

            return $out;
        }
        if (is_string($value)) {
            return $this->utf8String($value);
        }

        return $value;
    }

    private function utf8String(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (mb_check_encoding($value, 'UTF-8')) {
            return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
        }
        // 只丢弃非法字节，禁止按 GBK/Latin1 重解释（会造成双重编码乱码）
        if (function_exists('mb_scrub')) {
            $value = mb_scrub($value, 'UTF-8');
        } else {
            $ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = is_string($ignored) ? $ignored : '';
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function encodeYaml(array $doc): string
    {
        if (! class_exists(Yaml::class)) {
            throw new RuntimeException('生成 YAML 需要 symfony/yaml');
        }

        try {
            return Yaml::dump($doc, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        } catch (Throwable $e) {
            throw new RuntimeException('YAML 编码失败：' . $e->getMessage(), 0, $e);
        }
    }

    private function ensureDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }
}
