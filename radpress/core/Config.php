<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Config
{
    private array $site;
    private array $routes;
    private array $security;
    private array $update;
    private array $aif;

    private function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files
    ) {
        $this->site = $this->readOptionalJson('site.json', []);
        $this->routes = $this->readOptionalJson('routes.json', []);
        $this->security = $this->readOptionalJson('security.json', []);
        $this->update = $this->readOptionalJson('update.json', []);
        $this->aif = $this->readOptionalJson('aif.json', []);
    }

    public static function load(string $root): self
    {
        $files = new FileStore();
        $pathFile = $root . '/radpress/config/paths.json';
        $pathConfig = is_file($pathFile) ? $files->readJson($pathFile) : [];

        return new self(new Paths($root, $pathConfig), $files);
    }

    public function paths(): Paths
    {
        return $this->paths;
    }

    public function site(): array
    {
        return $this->site;
    }

    public function routes(): array
    {
        return $this->routes;
    }

    public function security(): array
    {
        return $this->security;
    }

    public function update(): array
    {
        return $this->update;
    }

    public function aif(): array
    {
        return $this->aif;
    }

    private function readOptionalJson(string $file, array $default): array
    {
        $path = $this->paths->configPath($file);
        return is_file($path) ? $this->files->readJson($path) : $default;
    }
}
