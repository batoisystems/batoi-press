<?php
declare(strict_types=1);

namespace Batoi\Press\Application\Social;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Security\SecretStore;
use RuntimeException;

final class SocialPublishing
{
    private readonly string $directory;
    private readonly SecretStore $secrets;

    public function __construct(Config $config, private readonly SocialApi $api = new SocialApi(), private readonly FileStore $files = new FileStore())
    {
        $this->directory = $config->paths()->dataPath('integrations/social-publishing');
        $this->secrets = new SecretStore($config->paths());
    }

    public function accounts(): array
    {
        $accounts = [];
        foreach (array_intersect_key($this->connections(), SocialApi::NAMES) as $platform => $record) {
            $accounts[$platform] = ['id' => $record['id'], 'name' => $record['name'], 'version' => self::connectionVersion($record)];
        }
        return $accounts;
    }

    public function connect(string $platform, string $token, string $expectedVersion = ''): array
    {
        $this->checkPlatform($platform);
        if (strlen($token) > 8192 || preg_match('/^[A-Za-z0-9._~+\/=\-]+$/D', $token) !== 1) {
            throw new RuntimeException('Enter a valid provider access token.', 422);
        }
        self::checkConnectionVersion($this->connections()[$platform] ?? [], $expectedVersion);
        $account = $this->api->identify($platform, $token);
        $record = $account + ['credential' => $this->secrets->encrypt($token), 'version' => bin2hex(random_bytes(16))];
        $this->files->mutateJson($this->directory . '/accounts.json', static function (array $accounts) use ($platform, $record, $expectedVersion): array {
            self::checkConnectionVersion($accounts[$platform] ?? [], $expectedVersion);
            $accounts[$platform] = $record;
            return $accounts;
        });
        return $account;
    }

    public function disconnect(string $platform, string $expectedVersion): void
    {
        $this->checkPlatform($platform);
        $this->files->mutateJson($this->directory . '/accounts.json', static function (array $accounts) use ($platform, $expectedVersion): array {
            self::checkConnectionVersion($accounts[$platform] ?? [], $expectedVersion);
            unset($accounts[$platform]);
            return $accounts;
        });
    }

    public function publish(string $platform, string $type, array $record, string $revision, string $expectedAccount, array $share): array
    {
        $this->checkPlatform($platform);
        if (!PublicationState::isPublic($record) || !hash_equals(ContentRevision::for($record), $revision)) {
            throw new RuntimeException('The saved content changed or is no longer public. Reload its editor.', 409);
        }
        $account = $this->connections()[$platform] ?? null;
        if ($account === null || $account['id'] !== $expectedAccount) {
            throw new RuntimeException('The destination changed. Reload the editor before publishing.', 409);
        }
        $receipt = $this->receiptPath($platform, $type, $record['slug'], $revision, $expectedAccount);
        $alreadySent = false;
        $claim = $this->files->mutateJson($receipt, static function (array $state) use (&$alreadySent): array {
            if (($state['state'] ?? '') === 'sent') {
                $alreadySent = true;
                return $state;
            }
            if ($state !== [] && !in_array($state['state'] ?? '', ['rejected', 'processing'], true)) {
                throw new RuntimeException('A submission is in progress or its result is uncertain. Check the destination account before taking further action.', 409);
            }
            return ['state' => 'pending', 'at' => date(DATE_ATOM), 'container' => $state['container'] ?? ''];
        });
        if ($alreadySent) return ['id' => $claim['id'], 'duplicate' => true];

        $container = $claim['container'];
        try {
            $token = $this->secrets->decrypt($account['credential']);
        } catch (RuntimeException $error) {
            $this->finish($receipt, 'rejected', container: $container);
            throw $error;
        }

        try {
            if ($platform === 'instagram') {
                $container = $this->api->prepareInstagram($token, $account['id'], $share, $container, function (string $prepared) use ($receipt, &$container): void {
                    $this->finish($receipt, 'pending', container: $prepared);
                    $container = $prepared;
                });
            }
            $id = $this->api->publish($platform, $token, $account['id'], $share, $container);
        } catch (RuntimeException $error) {
            $code = $error->getCode();
            if ($code >= 400 && $code < 500 && !in_array($code, [408, 409], true)) {
                $this->finish($receipt, $code === 425 ? 'processing' : 'rejected', container: $code === 410 ? '' : $container);
                throw $error;
            }
            // The provider may have published even if Press never received a usable response.
            $this->finish($receipt, 'unconfirmed');
            throw new RuntimeException('The provider did not confirm delivery. Check the destination account before taking further action.', 502);
        }
        $this->finish($receipt, 'sent', $id);
        return ['id' => $id, 'duplicate' => false];
    }

    public function delivery(string $platform, string $type, string $slug, string $revision, string $account): array
    {
        $path = $this->receiptPath($platform, $type, $slug, $revision, $account);
        $receipt = is_file($path) ? $this->files->readJson($path) : [];
        return array_intersect_key($receipt, ['state' => true, 'id' => true, 'at' => true]);
    }

    private function receiptPath(string $platform, string $type, string $slug, string $revision, string $account): string
    {
        $key = hash('sha256', implode("\n", [$platform, $account, $type, $slug, $revision]));
        return $this->directory . '/deliveries/' . $key . '.json';
    }

    private function finish(string $path, string $state, string $id = '', string $container = ''): void
    {
        $this->files->mutateJson($path, static fn (): array => ['state' => $state, 'id' => $id, 'at' => date(DATE_ATOM), 'container' => $container]);
    }

    private static function checkConnectionVersion(array $record, string $expected): void
    {
        if (!hash_equals(self::connectionVersion($record), $expected)) {
            throw new RuntimeException('The connection changed. Reload Social Publishing and try again.', 409);
        }
    }

    private static function connectionVersion(array $record): string
    {
        return $record === [] ? '' : ($record['version'] ?? hash('sha256', $record['credential']));
    }

    private function checkPlatform(string $platform): void
    {
        if (!isset(SocialApi::NAMES[$platform])) throw new RuntimeException('Select a supported social platform.', 422);
    }

    private function connections(): array
    {
        $path = $this->directory . '/accounts.json';
        return is_file($path) ? $this->files->readJson($path) : [];
    }
}
