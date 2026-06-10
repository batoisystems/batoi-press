<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

final class AifManager
{
    public function __construct(private readonly array $config)
    {
    }

    public function enabled(): bool
    {
        return ($this->config['enabled'] ?? false) === true;
    }

    public function provider(): AifProvider
    {
        return new DisabledAifProvider();
    }

    public function featureEnabled(string $feature): bool
    {
        $features = is_array($this->config['features'] ?? null) ? $this->config['features'] : [];
        return $this->enabled() && ($features[$feature] ?? false) === true;
    }

    public function assist(string $feature, array $context = []): array
    {
        if (!$this->featureEnabled($feature)) {
            return [
                'ok' => false,
                'feature' => $feature,
                'error' => 'Batoi AIF feature is disabled.',
            ];
        }

        return $this->provider()->assist($feature, $context);
    }

    public function status(): array
    {
        $provider = $this->provider();

        return [
            'enabled' => $this->enabled(),
            'provider' => (string)($this->config['provider'] ?? $provider->name()),
            'available' => $provider->available(),
            'workspace_required' => ($this->config['workspace_required'] ?? false) === true,
            'features' => is_array($this->config['features'] ?? null) ? $this->config['features'] : [],
        ];
    }
}
