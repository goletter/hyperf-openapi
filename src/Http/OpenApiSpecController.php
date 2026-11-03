<?php

declare(strict_types=1);

namespace Goletter\OpenApi\Http;

use Goletter\OpenApi\Generator\OpenApiGenerator;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Throwable;

/**
 * 按版本暴露 OpenAPI，供 Apifox 导入。
 *
 * - GET /openapi.json           版本索引
 * - GET /openapi/{version}.json 指定版本（如 /openapi/v1.json）
 */
final class OpenApiSpecController
{
    public function __construct(
        private OpenApiGenerator $generator,
        private ConfigInterface $config,
    ) {
    }

    public function index(ResponseInterface $response): PsrResponseInterface
    {
        try {
            $body = $this->generator->toJsonIndex();
        } catch (Throwable $e) {
            return $response->json(['message' => $e->getMessage()])->withStatus(500);
        }

        return $this->raw($response, $body, 'application/json');
    }

    public function json(ResponseInterface $response, string $version): PsrResponseInterface
    {
        return $this->respond($response, $version, 'json', 'application/json');
    }

    public function yaml(ResponseInterface $response, string $version): PsrResponseInterface
    {
        return $this->respond($response, $version, 'yaml', 'application/yaml');
    }

    private function respond(
        ResponseInterface $response,
        string $version,
        string $format,
        string $contentType
    ): PsrResponseInterface {
        try {
            $body = $this->resolveBody($version, $format);
        } catch (Throwable $e) {
            return $response->json(['message' => $e->getMessage()])->withStatus(500);
        }

        return $this->raw($response, $body, $contentType);
    }

    private function raw(ResponseInterface $response, string $body, string $contentType): PsrResponseInterface
    {
        return $response
            ->withHeader('Content-Type', $contentType . '; charset=utf-8')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withBody(new SwooleStream($body));
    }

    private function resolveBody(string $version, string $format): string
    {
        $mode = (string) $this->config->get('openapi.route.mode', 'generate');
        $version = strtolower($version);
        if ($version !== '' && $version[0] !== 'v') {
            $version = 'v' . $version;
        }

        if ($mode === 'file') {
            $dir = (string) $this->config->get('openapi.output.dir', BASE_PATH . '/storage/openapi');
            $pattern = (string) $this->config->get(
                $format === 'yaml' ? 'openapi.output.yaml_pattern' : 'openapi.output.json_pattern',
                $format === 'yaml' ? 'openapi.{version}.yaml' : 'openapi.{version}.json'
            );
            $path = rtrim($dir, '/') . '/' . str_replace('{version}', $version, $pattern);
            if (! is_file($path)) {
                throw new \RuntimeException(sprintf(
                    '规格文件不存在：%s，请先执行 php bin/hyperf.php openapi:generate --version=%s',
                    $path,
                    $version
                ));
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('无法读取：' . $path);
            }

            return $contents;
        }

        return $format === 'yaml'
            ? $this->generator->toYaml($version)
            : $this->generator->toJson($version);
    }
}
