<?php

declare(strict_types=1);

namespace Goletter\OpenApi\Listener;

use Goletter\OpenApi\Http\OpenApiSpecController;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Router;

/**
 * 注册分版本 OpenAPI 路由。
 *
 * - GET /openapi.json              版本索引
 * - GET /openapi/{version}.json    如 /openapi/v1.json
 * - GET /openapi/{version}.yaml
 */
final class RegisterOpenApiRouteListener implements ListenerInterface
{
    public function __construct(private ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $this->config->get('openapi.enable', true)) {
            return;
        }
        if (! $this->config->get('openapi.route.enable', true)) {
            return;
        }

        if (class_exists(DispatcherFactory::class)) {
            try {
                \Hyperf\Context\ApplicationContext::getContainer()->get(DispatcherFactory::class);
            } catch (\Throwable) {
            }
        }

        $indexPath = (string) $this->config->get('openapi.route.index_path', '/openapi.json');
        if ($indexPath !== '') {
            Router::get($indexPath, [OpenApiSpecController::class, 'index']);
        }

        $jsonTpl = (string) $this->config->get('openapi.route.json_path', '/openapi/{version}.json');
        $yamlTpl = (string) $this->config->get('openapi.route.yaml_path', '/openapi/{version}.yaml');

        if ($jsonTpl !== '') {
            Router::get($jsonTpl, [OpenApiSpecController::class, 'json']);
        }
        if ($yamlTpl !== '') {
            Router::get($yamlTpl, [OpenApiSpecController::class, 'yaml']);
        }
    }
}
