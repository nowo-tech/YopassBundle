<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\Ui\RetentionNotice;
use Nowo\YopassBundle\Ui\YopassPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

use function glob;
use function pathinfo;
use function sprintf;
use function str_contains;

use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * `%max_age%` of the retention notice is a translated duration, never the raw PHP modifier ("más de 14 days").
 */
final class RetentionNoticeTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../src/Resources/translations';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function durations(): iterable
    {
        yield 'en plural' => ['en', '14 days', 'Shares older than 14 days are automatically deleted.'];
        yield 'en one' => ['en', '1 month', 'Shares older than 1 month are automatically deleted.'];
        yield 'es plural' => ['es', '14 days', 'Los secretos de más de 14 días se eliminan automáticamente.'];
        yield 'es one' => ['es', '1 month', 'Los secretos de más de 1 mes se eliminan automáticamente.'];
        yield 'fr plural' => ['fr', '14 days', 'Les partages de plus de 14 jours sont supprimés automatiquement.'];
        yield 'fr one' => ['fr', '1 month', 'Les partages de plus de 1 mois sont supprimés automatiquement.'];
        yield 'de plural' => ['de', '14 days', 'Freigaben älter als 14 Tage werden automatisch gelöscht.'];
        yield 'de one' => ['de', '1 month', 'Freigaben älter als 1 Monat werden automatisch gelöscht.'];
        yield 'it plural' => ['it', '14 days', 'I segreti più vecchi di 14 giorni vengono eliminati automaticamente.'];
        yield 'it one' => ['it', '1 month', 'I segreti più vecchi di 1 mese vengono eliminati automaticamente.'];
        yield 'nl plural' => ['nl', '14 days', 'Geheimen ouder dan 14 dagen worden automatisch verwijderd.'];
        yield 'nl one' => ['nl', '1 month', 'Geheimen ouder dan 1 maand worden automatisch verwijderd.'];
        yield 'pt plural' => ['pt', '14 days', 'Segredos com mais de 14 dias são eliminados automaticamente.'];
        yield 'pt one' => ['pt', '1 month', 'Segredos com mais de 1 mês são eliminados automaticamente.'];
        yield 'plus sign' => ['es', '+2 weeks', 'Los secretos de más de 2 semanas se eliminan automáticamente.'];
        yield 'singular N' => ['es', '3 hour', 'Los secretos de más de 3 horas se eliminan automáticamente.'];
        yield 'plural one' => ['es', '1 years', 'Los secretos de más de 1 año se eliminan automáticamente.'];
        yield 'minutes' => ['es', '30 minutes', 'Los secretos de más de 30 minutos se eliminan automáticamente.'];
        yield 'upper case' => ['es', ' 14 DAYS ', 'Los secretos de más de 14 días se eliminan automáticamente.'];
    }

    #[DataProvider('durations')]
    public function testCommonModifiersBecomeATranslatedDuration(string $locale, string $maxAge, string $expected): void
    {
        self::assertSame($expected, $this->render(RetentionNotice::label($maxAge), $locale));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherModifiers(): iterable
    {
        yield 'calendar phrase' => ['first day of next month'];
        yield 'compound' => ['1 month 2 days'];
        yield 'word' => ['fortnight'];
        yield 'abbreviation' => ['30 min'];
        yield 'negative' => ['-14 days'];
        yield 'ago' => ['14 days ago'];
        yield 'too long' => ['99999999999 days'];
        yield 'trailing line' => ["14 days\nDROP"];
        yield 'empty' => [''];
    }

    #[DataProvider('otherModifiers')]
    public function testAnyOtherModifierGetsTheNoticeWithoutADuration(string $maxAge): void
    {
        $label = RetentionNotice::label($maxAge);

        self::assertSame('yopass.list.retention_notice_unspecified', $label->key);
        self::assertSame([], $label->params);
        self::assertSame('Los secretos antiguos se eliminan automáticamente.', $this->render($label, 'es'));
    }

    public function testNoIcuFormatterGetsTheNoticeWithoutADuration(): void
    {
        self::assertSame('yopass.list.retention_notice_unspecified', RetentionNotice::label('14 days', false)->key);
    }

    public function testEveryLocaleDefinesEveryUnitAndTheNoticeWithoutADuration(): void
    {
        $locales = 0;
        foreach (glob(self::DIR . '/NowoYopassBundle.*.yaml') ?: [] as $file) {
            ++$locales;
            $locale = pathinfo(pathinfo($file, PATHINFO_FILENAME), PATHINFO_EXTENSION);

            $main = Yaml::parseFile($file);
            self::assertNotEmpty($main['yopass']['list']['retention_notice_unspecified'] ?? null, $locale);
            self::assertStringNotContainsString('%max_age%', $main['yopass']['list']['retention_notice_unspecified'], $locale);

            $icu = Yaml::parseFile(sprintf('%s/NowoYopassBundle+intl-icu.%s.yaml', self::DIR, $locale));
            foreach (['minute', 'hour', 'day', 'week', 'month', 'year'] as $unit) {
                self::assertTrue(str_contains($icu['yopass']['list']['retention_duration'][$unit] ?? '', 'plural'), $locale . ' ' . $unit);
            }
        }

        self::assertSame(7, $locales);
    }

    private function render(Label $label, string $locale): string
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', sprintf('%s/NowoYopassBundle.%s.yaml', self::DIR, $locale), $locale, YopassPages::DOMAIN);
        $translator->addResource('yaml', sprintf('%s/NowoYopassBundle+intl-icu.%s.yaml', self::DIR, $locale), $locale, YopassPages::DOMAIN . '+intl-icu');

        return $translator->trans($label->key, $label->params, $label->domain, $locale);
    }
}
