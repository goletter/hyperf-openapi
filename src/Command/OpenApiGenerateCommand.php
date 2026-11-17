<?php

declare(strict_types=1);

namespace Goletter\OpenApi\Command;

use Goletter\OpenApi\Generator\OpenApiGenerator;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 按版本生成 OpenAPI（可导入 Apifox）。
 *
 *   php bin/hyperf.php openapi:generate
 *   php bin/hyperf.php openapi:generate --api-version=v1
 *
 * 注意：不要用 --version（与 Symfony/Hyperf 全局 --version 冲突，命令不会执行）。
 */
class OpenApiGenerateCommand extends HyperfCommand
{
    public function __construct()
    {
        parent::__construct('openapi:generate');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('按 API 版本扫描 Controller 生成 OpenAPI 3（可导入 Apifox）');
        // 不能用 --version / -V：会被 Application 当成「打印框架版本」吃掉
        $this->addOption('api-version', 'A', InputOption::VALUE_REQUIRED, '只生成指定 API 版本，如 v1；省略则生成全部');
    }

    /**
     * Hyperf 3.1.x 的 parseVerbosity(null) 在 PHP 8.5 会触发
     * “Using null as an array offset is deprecated”，且 ErrorExceptionHandler 会直接中断命令。
     * 未显式传级别时改为整数 VERBOSITY_NORMAL，绕开 null 下标。
     *
     * @param string $string
     * @param null|string $style
     * @param null|int|string $verbosity
     */
    public function line($string, $style = null, $verbosity = null)
    {
        parent::line($string, $style, $verbosity ?? OutputInterface::VERBOSITY_NORMAL);
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $config = $container->get(ConfigInterface::class);
        $generator = $container->get(OpenApiGenerator::class);

        $versionOpt = $this->input->getOption('api-version');
        $version = is_string($versionOpt) && $versionOpt !== '' ? $versionOpt : null;

        $this->line('扫描版本：' . implode(', ', $version !== null ? [$version] : $generator->discoverVersions()));
        foreach ((array) $config->get('openapi.gateways', []) as $gateway) {
            $this->line(sprintf(
                '  gateway %s → %s',
                $gateway['prefix'] ?? '',
                $gateway['path'] ?? ''
            ));
        }

        try {
            $written = $generator->write($version);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            foreach ($generator->getScanDebug() as $line) {
                $this->line('  · ' . $line);
            }

            return;
        }

        foreach ($generator->getScanDebug() as $line) {
            $this->line('  · ' . $line);
        }

        foreach ($written as $row) {
            $this->info(sprintf('[%s] paths=%d', $row['version'], $row['paths']));
            if ($row['json']) {
                $this->line('  JSON: ' . $this->relative((string) $row['json']));
            }
            if ($row['yaml']) {
                $this->line('  YAML: ' . $this->relative((string) $row['yaml']));
            }
        }

        $this->line('');
        $this->line('导入 Apifox：');
        $this->line('  目录结构：v1/Api/需求管理、v2/Api/…（x-apifox-folder，模块名=类注释）');
        $this->line('  推荐一次导入全量：storage/openapi/openapi.json（多版本 path 为 /api/xxx#v1，# 后仅区分版本）');
        $this->line('  或按版本：storage/openapi/openapi.v1.json（纯真实 path，无 #）');
        $this->line('  「导入到」选「创建新模块」+ 勾选「导入 Servers 为环境」');
        $envFile = rtrim((string) $config->get('openapi.output.dir', BASE_PATH . '/storage/openapi'), '/') . '/apifox.environments.json';
        if (is_file($envFile)) {
            $this->line('  前置 URL 仍空时导入：' . $this->relative($envFile));
        }
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, BASE_PATH . '/')
            ? substr($path, strlen(BASE_PATH) + 1)
            : $path;
    }
}
