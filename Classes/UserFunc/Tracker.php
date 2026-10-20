<?php

declare(strict_types=1);

namespace HitKeep\Typo3\UserFunc;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Page\PageParts;

final readonly class Tracker
{
    public function __construct(private AssetCollector $assets, private Context $context) {}

    #[AsAllowedCallable]
    public function register(string $content, array $configuration, ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        $parts = $request->getAttribute('frontend.page.parts');
        if (!ApplicationType::fromRequest($request)->isFrontend()
            || !$site instanceof Site || !$parts instanceof PageParts
            || strtolower(trim(explode(';', $parts->getHttpContentType())[0])) !== 'text/html'
            || $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)
            || $this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn', false)
            || $this->context->getPropertyFromAspect('frontend.preview', 'isPreview', false)
        ) {
            return '';
        }
        $settings = $site->getSettings();
        $host = $settings->get('hitkeep.host', '');
        if ($settings->get('hitkeep.enabled', false) !== true || !is_string($host) || !$this->validHost($host)) {
            return '';
        }
        $exclusions = $settings->get('hitkeep.excludePaths', []);
        if (!is_array($exclusions)) {
            return '';
        }
        $path = rawurldecode($request->getUri()->getPath());
        foreach ($exclusions as $prefix) {
            if (!is_string($prefix) || !str_starts_with($prefix, '/')) {
                return '';
            }
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return '';
            }
        }
        $this->assets->addJavaScript('hitkeep-tracker', rtrim($host, '/') . '/hk.js', ['async' => true]);
        return '';
    }

    private function validHost(string $host): bool
    {
        if (!filter_var($host, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f<>"\'\\\\%?#@]/', $host)) {
            return false;
        }
        $url = parse_url($host);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) {
            return false;
        }
        $path = $url['path'] ?? '';
        return preg_match('!^(?:/[A-Za-z0-9_~.\-]+)*/?$!D', $path) === 1
            && !in_array('.', explode('/', $path), true)
            && !in_array('..', explode('/', $path), true);
    }
}
