<?php

declare(strict_types=1);
namespace HitKeep\Typo3\Tests\Functional;

use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

final class TrackerRenderingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['hitkeep/typo3'];
    protected array $configurationToUseInTestInstance = ['SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class]]]]];

    protected function setUp(): void
    {
        parent::setUp();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        foreach ([1, 2] as $id) {
            $pages->insert('pages', ['uid' => $id, 'pid' => 0, 'title' => 'Site ' . $id, 'slug' => '/', 'is_siteroot' => 1]);
            $this->setUpFrontendRootPage($id, [], ['config' => file_get_contents(__DIR__ . '/Fixtures/setup.typoscript')]);
            $this->getConnectionPool()->getConnectionForTable('sys_template')->update('sys_template', ['clear' => 0], ['pid' => $id]);
            $this->site($id);
        }
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Private', 'slug' => '/private']);
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'editor', 'admin' => 1]);
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->siteConfigurationChanged();
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
    }

    private function site(int $id, bool $enabled = true): void
    {
        GeneralUtility::makeInstance(SiteWriter::class)->write('site' . $id, [
            'rootPageId' => $id, 'base' => 'https://site' . $id . '.example/',
            'languages' => [['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'], ['languageId' => 1, 'title' => 'German', 'navigationTitle' => 'German', 'base' => 'https://language' . $id . '.example/', 'locale' => 'de_DE.UTF-8', 'flag' => 'de', 'fallbackType' => 'free', 'fallbacks' => '0']],
            'dependencies' => ['hitkeep/tracker'],
        ]);
        GeneralUtility::makeInstance(SiteWriter::class)->writeSettings('site' . $id, ['hitkeep.enabled' => $enabled, 'hitkeep.host' => 'https://analytics' . $id . '.example/prefix', 'hitkeep.excludePaths' => ['/private']]);
    }

    public function testWarmCacheContainsNoTrackerEligibility(): void
    {
        $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        self::assertNotSame('', $response->getHeaderLine('X-TYPO3-Debug-Cache'));
        $cache = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('pages');
        $entries = $cache->getBackend()->findIdentifiersByTag('pageId_1');
        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $state = $cache->get($entry);
            self::assertStringNotContainsString('/hk.js', $state['content']);
            self::assertStringNotContainsString('hitkeep-tracker', $state['assetCollectorState']);
            self::assertNotEmpty($state['INTincScript']);
        }
    }

    public function testDisableAndEnableAfterDocumentedPurge(): void
    {
        $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        $this->site(1, false);
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->siteConfigurationChanged();
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        self::assertStringNotContainsString('/hk.js', (string)$response->getBody());
        self::assertStringContainsString('Public fixture', (string)$response->getBody());
        $this->site(1, true);
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->siteConfigurationChanged();
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        self::assertSame(1, substr_count((string)$response->getBody(), '/hk.js'));
    }

    public function testBrowserEvidence(): void
    {
        $directory = getenv('HITKEEP_BROWSER_EVIDENCE');
        if (!$directory) {
            self::markTestSkipped('Set HITKEEP_BROWSER_EVIDENCE to export rendered browser fixtures.');
        }
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $writer->writeSettings('site1', ['hitkeep.enabled' => true, 'hitkeep.host' => getenv('HITKEEP_BROWSER_HOST')]);
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->siteConfigurationChanged();
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
        foreach (['enabled', 'staff', 'disabled'] as $mode) {
            if ($mode === 'disabled') {
                $writer->writeSettings('site1', ['hitkeep.enabled' => false]);
                GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->siteConfigurationChanged();
                GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
            }
            $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'), $mode === 'staff' ? (new InternalRequestContext())->withBackendUserId(1) : null);
            self::assertSame(200, $response->getStatusCode());
            file_put_contents($directory . '/' . $mode . '.html', (string)$response->getBody());
        }
    }

    public function testLanguageDomainUsesSameSiteSettings(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://language1.example/'));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(1, substr_count((string)$response->getBody(), 'https://analytics1.example/prefix/hk.js'));
    }

    public function testRemovingSetPreservesContentAndStopsRegistration(): void
    {
        $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $configuration = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->load('site1');
        $configuration['dependencies'] = [];
        $writer->write('site1', $configuration);
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\SiteConfiguration::class)->siteConfigurationChanged();
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Public fixture', (string)$response->getBody());
        self::assertStringNotContainsString('/hk.js', (string)$response->getBody());
        self::assertSame(3, (int)$this->getConnectionPool()->getConnectionForTable('pages')->count('*', 'pages', []));
    }

    public function testExcludedPath(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/private'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('/hk.js', (string)$response->getBody());
    }

    public function testNonHtmlResponse(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://site1.example/?type=99'));
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('/hk.js', (string)$response->getBody());
    }

    public function testColdWarmSiteIsolationAndStaffOrders(): void
    {
        foreach ([[1,false],[1,false],[1,true],[1,false],[2,true],[2,false],[1,false],[2,false]] as [$site, $staff]) {
            $response = $this->executeFrontendSubRequest(new InternalRequest('https://site' . $site . '.example/'), $staff ? (new InternalRequestContext())->withBackendUserId(1) : null);
            self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
            $html = (string)$response->getBody();
            self::assertSame($staff ? 0 : 1, substr_count($html, '/hk.js'), $html);
            if (!$staff) {
                self::assertStringContainsString('https://analytics' . $site . '.example/prefix/hk.js', $html);
                self::assertStringContainsString('async', $html);
            }
        }
    }
}
