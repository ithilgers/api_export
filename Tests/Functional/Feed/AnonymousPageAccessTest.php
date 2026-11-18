<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Feed;

use Ithilgers\ApiExport\Feed\AnonymousPageAccess;
use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;

/**
 * Page tree (Fixtures/Database/Feed.csv): 1 root > 10 folder > 11 subfolder,
 * 1 > 20 folder, 1 > 40 detail page.
 */
final class AnonymousPageAccessTest extends AbstractFeedTestCase
{
    public static function frontendGroupDataProvider(): array
    {
        return [
            'no group' => ['', true],
            'zero' => ['0', true],
            'hide at login (-1)' => ['-1', true],
            'show at any login (-2)' => ['-2', false],
            'specific group' => ['1', false],
            'specific group or hide at login' => ['1,-1', true],
        ];
    }

    #[Test]
    #[DataProvider('frontendGroupDataProvider')]
    public function ownFrontendGroupDecides(string $feGroup, bool $expected): void
    {
        $this->updateRecord('pages', 20, ['fe_group' => $feGroup]);

        $access = $this->access();

        self::assertSame($expected, $access->isFolderAccessible(20));
        self::assertSame($expected, $access->isPageVisible(20));
    }

    #[Test]
    public function frontendGroupIsInheritedViaExtendToSubpages(): void
    {
        $this->updateRecord('pages', 10, ['fe_group' => '1', 'extendToSubpages' => 1]);

        $access = $this->access();

        self::assertFalse($access->isFolderAccessible(11));
        self::assertFalse($access->isPageVisible(11));
    }

    #[Test]
    public function frontendGroupWithoutExtendToSubpagesOnlyAppliesToThePageItself(): void
    {
        $this->updateRecord('pages', 10, ['fe_group' => '1', 'extendToSubpages' => 0]);

        $access = $this->access();

        self::assertFalse($access->isFolderAccessible(10));
        self::assertTrue($access->isFolderAccessible(11));
        self::assertTrue($access->isPageVisible(11));
    }

    public static function hiddenDataProvider(): array
    {
        return [
            'hidden' => [['hidden' => 1]],
            'start in the future' => [['starttime' => 2147000000]],
            'stop in the past' => [['endtime' => 978307200]],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('hiddenDataProvider')]
    public function hiddenCountsForPagesButNotForFolders(array $fields): void
    {
        $this->updateRecord('pages', 40, $fields);

        $access = $this->access();

        self::assertTrue($access->isFolderAccessible(40));
        self::assertFalse($access->isPageVisible(40));
    }

    #[Test]
    public function hiddenParentWithExtendToSubpagesHidesSubpages(): void
    {
        $this->updateRecord('pages', 10, ['hidden' => 1, 'extendToSubpages' => 1]);

        $access = $this->access();

        self::assertTrue($access->isFolderAccessible(11));
        self::assertFalse($access->isPageVisible(11));
    }

    #[Test]
    public function deletedOrMissingPagesAreNeverReachable(): void
    {
        $this->updateRecord('pages', 20, ['deleted' => 1]);

        $access = $this->access();

        self::assertFalse($access->isFolderAccessible(20));
        self::assertFalse($access->isPageVisible(20));
        self::assertFalse($access->isFolderAccessible(999));
        self::assertFalse($access->isPageVisible(0));
    }

    #[Test]
    public function loginsAndPreviewOfTheCurrentContextAreIgnored(): void
    {
        $this->updateRecord('pages', 20, ['fe_group' => '1']);
        $this->updateRecord('pages', 40, ['hidden' => 1]);
        $context = $this->get(Context::class);
        $context->setAspect('frontend.user', new UserAspect(null, [0, -1, 1]));
        $context->setAspect('visibility', new VisibilityAspect(true, true, true));

        $access = $this->access();

        self::assertFalse($access->isFolderAccessible(20));
        self::assertFalse($access->isPageVisible(40));
        self::assertSame([0, -1], $access->getContext()->getPropertyFromAspect('frontend.user', 'groupIds'));
    }

    private function access(): AnonymousPageAccess
    {
        return $this->get(AnonymousPageAccess::class);
    }
}
