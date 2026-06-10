<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

final class ContentAssistant
{
    public function __construct(private readonly AifProvider $provider)
    {
    }

    public function suggestSeoDescription(array $content): array
    {
        return $this->provider->assist('seo_description', $content);
    }

    public function suggestTags(array $content): array
    {
        return $this->provider->assist('tags', $content);
    }

    public function summarize(array $content): array
    {
        return $this->provider->assist('summary', $content);
    }
}
