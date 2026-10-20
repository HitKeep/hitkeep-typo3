<?php

declare(strict_types=1);
namespace HitKeep\Typo3\Tests\Unit;

use HitKeep\Typo3\UserFunc\Tracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Frontend\Page\PageParts;

final class TrackerTest extends TestCase
{
    public static function hosts(): iterable
    {
        yield ['https://analytics.example', 'https://analytics.example/hk.js'];
        yield ['https://custom.example:8443/prefix/', 'https://custom.example:8443/prefix/hk.js'];
        foreach (['', 'http://example.org', '//example.org', 'javascript:alert(1)', 'https://user:pass@example.org', 'https://example.org?x=1', 'https://example.org#x', "https://example.org\n", 'https://example.org/" onload="x', 'https://example.org/%22', 'https://example.org/../x', 'https://example.org\\evil', 'https://example.org:99999', 'https://example.org//x'] as $host) {
            yield [$host, null];
        }
    }

    #[DataProvider('hosts')]
    public function testHostValidation(string $host, ?string $expected): void
    {
        $assets = new AssetCollector();
        $tracker = new Tracker($assets, $this->context());
        $tracker->register('', [], $this->request(['hitkeep.enabled' => true, 'hitkeep.host' => $host]));
        self::assertSame($expected, $assets->getJavaScripts()['hitkeep-tracker']['source'] ?? null);
    }

    public function testDisabledExclusionsNonHtmlAndDeduplication(): void
    {
        $assets = new AssetCollector();
        $tracker = new Tracker($assets, $this->context());
        $settings = ['hitkeep.enabled' => true, 'hitkeep.host' => 'https://a.example', 'hitkeep.excludePaths' => ['/private']];
        foreach (['/private', '/private/child', '/%70rivate/child'] as $path) {
            $tracker->register('', [], $this->request($settings, $path));
        }
        $parts = new PageParts();
        $parts->setHttpContentType('application/json');
        $tracker->register('', [], $this->request($settings)->withAttribute('frontend.page.parts', $parts));
        $tracker->register('', [], $this->request(array_replace($settings, ['hitkeep.enabled' => false])));
        self::assertSame([], $assets->getJavaScripts());
        $request = $this->request($settings, '/private-other');
        self::assertSame('', $tracker->register('', [], $request));
        $tracker->register('', [], $request);
        self::assertCount(1, $assets->getJavaScripts());
        self::assertTrue($assets->getJavaScripts()['hitkeep-tracker']['attributes']['async']);
        self::assertArrayNotHasKey('data-collect-dnt', $assets->getJavaScripts()['hitkeep-tracker']['attributes']);
    }

    public function testStaffPreviewBackendAndAuthenticatedFrontendAreSuppressed(): void
    {
        foreach (['staff', 'preview', 'backend', 'frontendUser'] as $mode) {
            $context = $this->context();
            $request = $this->request(['hitkeep.enabled' => true, 'hitkeep.host' => 'https://analytics.example']);
            if ($mode === 'preview') {
                $context->setAspect('frontend.preview', new \TYPO3\CMS\Frontend\Aspect\PreviewAspect(true));
            } elseif ($mode === 'backend') {
                $request = $request->withAttribute('applicationType', 2);
            } else {
                $user = new \TYPO3\CMS\Core\Authentication\BackendUserAuthentication();
                $user->user = ['uid' => 1];
                $context->setAspect($mode === 'staff' ? 'backend.user' : 'frontend.user', new UserAspect($user));
            }
            $assets = new AssetCollector();
            (new Tracker($assets, $context))->register('', [], $request);
            self::assertSame([], $assets->getJavaScripts(), $mode);
        }
    }

    private function context(): Context
    {
        $context = new Context();
        $context->setAspect('frontend.preview', new \TYPO3\CMS\Frontend\Aspect\PreviewAspect());
        return $context;
    }

    private function request(array $settings, string $path = '/'): ServerRequest
    {
        $site = new Site('fixture', 1, ['base' => 'https://site.example/'], SiteSettings::create(new Settings($settings)));
        return (new ServerRequest('https://site.example' . $path))->withAttribute('applicationType', 1)->withAttribute('site', $site)->withAttribute('frontend.page.parts', new PageParts());
    }
}
