<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemMetaRepository;

/**
 * The payment providers' keys (docs/shop-plan.md, section 5), in their own `system_meta` document (`shop_secrets`): never in the
 * Shop settings, never in a settings export, never sent to a browser or written to a log. A screen is told only whether each one
 * is set and its last four characters; a blank input keeps what is stored.
 */
final class ShopSecrets
{
    public const META = 'shop_secrets';
    /** Each provider's secret keys. */
    public const KEYS = ['viva' => ['api_key', 'client_secret'], 'cardlink' => ['shared_secret']];

    public function __construct(private SystemMetaRepository $meta)
    {
    }

    /** @return array<string, string> a provider's secrets */
    public function of(string $provider): array
    {
        $all = $this->meta->isAvailable() ? ($this->meta->getJson(self::META) ?? []) : [];
        $mine = is_array($all[$provider] ?? null) ? $all[$provider] : [];
        return array_map(static fn(mixed $v): string => is_scalar($v) ? (string)$v : '', $mine);
    }

    /**
     * Stores the secrets sent from a screen: a blank one keeps what is stored, `clear` removes them all.
     *
     * @param array<string, mixed> $sent
     */
    public function save(string $provider, array $sent, bool $clear = false): void
    {
        if (!$this->meta->isAvailable() || !isset(self::KEYS[$provider])) {
            return;
        }
        $all = $this->meta->getJson(self::META) ?? [];
        $mine = $clear ? [] : (is_array($all[$provider] ?? null) ? $all[$provider] : []);
        foreach (self::KEYS[$provider] as $key) {
            $value = trim(is_scalar($sent[$key] ?? null) ? (string)$sent[$key] : '');
            if ($value !== '') {
                $mine[$key] = mb_substr($value, 0, 400);
            }
        }
        $all[$provider] = $mine;
        $this->meta->setJson(self::META, $all);
    }

    /** @return array<string, array{set: bool, last4: string}> what a screen may know */
    public function view(string $provider): array
    {
        $mine = $this->of($provider);
        $view = [];
        foreach (self::KEYS[$provider] ?? [] as $key) {
            $value = $mine[$key] ?? '';
            $view[$key] = ['set' => $value !== '', 'last4' => $value !== '' ? substr($value, -4) : ''];
        }
        return $view;
    }
}
