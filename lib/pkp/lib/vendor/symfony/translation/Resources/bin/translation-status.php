<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if ('cli' !== \PHP_SAPI) {
    throw new Exception('This script must be run from the command line.');
}

$usageInstructions = <<<END

  Usage instructions
  -------------------------------------------------------------------------------

  $ cd symfony-code-root-directory/

  # show the translation status of all locales
  $ php translation-status.php

  # only show the translation status of incomplete or erroneous locales
  $ php translation-status.php --incomplete

  # show the translation status of all locales, all their missing translations and mismatches between trans-unit id and source
  $ php translation-status.php -v

  # show the status of a single locale
  $ php translation-status.php fr

  # show the status of a single locale, missing translations and mismatches between trans-unit id and source
  $ php translation-status.php fr -v

END;

$config = [
    'verbose_output' => false,
    'locale_to_analyze' => null,
    'include_completed_languages' => true,
    'original_files' => [
        'src/Symfony/Component/Form/Resources/translations/validators.en.xlf',
        'src/Symfony/Component/Security/Core/Resources/translations/security.en.xlf',
        'src/Symfony/Component/Validator/Resources/translations/validators.en.xlf',
    ],
];

$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];

if ($argc > 4) {
    echo htmlspecialchars(str_replace('translation-status.php', $argv[0], $usageInstructions), ENT_QUOTES, 'UTF-8'); // ✅ prevent XSS
    exit(1);
}

foreach (array_slice($argv, 1) as $argumentOrOption) {
    if ('--incomplete' === $argumentOrOption) {
        $config['include_completed_languages'] = false;
        continue;
    }

    if (str_starts_with($argumentOrOption, '-')) {
        $config['verbose_output'] = true;
    } else {
        // ✅ sanitize locale input (alphanumeric + dash/underscore only)
        $safeLocale = preg_replace('/[^a-zA-Z0-9_-]/', '', $argumentOrOption);
        $config['locale_to_analyze'] = $safeLocale;
    }
}

foreach ($config['original_files'] as $originalFilePath) {
    if (!file_exists($originalFilePath)) {
        echo sprintf(
            'The following file does not exist. Make sure that you execute this command at the root dir of the Symfony code repository.%s  %s',
            \PHP_EOL,
            htmlspecialchars($originalFilePath, ENT_QUOTES, 'UTF-8') // ✅ prevent XSS
        );
        exit(1);
    }
}

$totalMissingTranslations = 0;
$totalTranslationMismatches = 0;

foreach ($config['original_files'] as $originalFilePath) {
    $translationFilePaths = findTranslationFiles($originalFilePath, $config['locale_to_analyze']);
    $translationStatus = calculateTranslationStatus($originalFilePath, $translationFilePaths);

    $totalMissingTranslations += array_sum(array_map(fn($t) => count($t['missingKeys']), $translationStatus));
    $totalTranslationMismatches += array_sum(array_map(fn($t) => count($t['mismatches']), $translationStatus));

    printTranslationStatus($originalFilePath, $translationStatus, $config['verbose_output'], $config['include_completed_languages']);
}

exit($totalTranslationMismatches > 0 ? 1 : 0);

/**
 * ✅ Ensure the given file path is safe (no traversal, no URLs)
 */
function safeFilePath(string $path): string
{
    if (preg_match('#^(https?|ftp)://#i', $path)) {
        throw new \RuntimeException("Remote URLs are not allowed for file access (SSRF protection).");
    }

    $realBase = realpath(getcwd());
    $realPath = realpath($path);

    if ($realPath === false || strpos($realPath, $realBase) !== 0) {
        throw new \RuntimeException("Invalid or unsafe file path detected (possible path traversal): $path");
    }

    return $realPath;
}

function findTranslationFiles($originalFilePath, $localeToAnalyze)
{
    $translations = [];

    $translationsDir = dirname($originalFilePath);
    $originalFileName = basename($originalFilePath);
    $translationFileNamePattern = str_replace('.en.', '.*.', $originalFileName);

    $translationFiles = glob($translationsDir . '/' . $translationFileNamePattern, \GLOB_NOSORT);
    sort($translationFiles);
    foreach ($translationFiles as $filePath) {
        $locale = extractLocaleFromFilePath($filePath);

        if (null !== $localeToAnalyze && $locale !== $localeToAnalyze) {
            continue;
        }

        $translations[$locale] = $filePath;
    }

    return $translations;
}

function calculateTranslationStatus($originalFilePath, $translationFilePaths)
{
    $translationStatus = [];
    $allTranslationKeys = extractTranslationKeys($originalFilePath);

    foreach ($translationFilePaths as $locale => $translationPath) {
        $translatedKeys = extractTranslationKeys($translationPath);
        $missingKeys = array_diff_key($allTranslationKeys, $translatedKeys);
        $mismatches = findTransUnitMismatches($allTranslationKeys, $translatedKeys);

        $translationStatus[$locale] = [
            'total' => count($allTranslationKeys),
            'translated' => count($translatedKeys),
            'missingKeys' => $missingKeys,
            'mismatches' => $mismatches,
        ];
        $translationStatus[$locale]['is_completed'] = isTranslationCompleted($translationStatus[$locale]);
    }

    return $translationStatus;
}

function isTranslationCompleted(array $translationStatus): bool
{
    return $translationStatus['total'] === $translationStatus['translated']
        && 0 === count($translationStatus['mismatches']);
}

function printTranslationStatus($originalFilePath, $translationStatus, $verboseOutput, $includeCompletedLanguages)
{
    printTitle($originalFilePath);
    printTable($translationStatus, $verboseOutput, $includeCompletedLanguages);
    echo \PHP_EOL . \PHP_EOL;
}

function extractLocaleFromFilePath($filePath)
{
    $parts = explode('.', $filePath);
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $parts[count($parts) - 2]); // ✅ sanitize locale
}

/**
 * ✅ Secure XML parsing with protections against XXE, SSRF, and Path Traversal
 */
function extractTranslationKeys($filePath)
{
    $translationKeys = [];

    $safePath = safeFilePath($filePath); // ✅ verify safe local file
    $xmlContent = @file_get_contents($safePath);
    if ($xmlContent === false) {
        throw new \RuntimeException("Unable to read file: $safePath");
    }

    // ✅ disable external entities to prevent XXE
    $disableEntities = libxml_disable_entity_loader(true);
    $useInternalErrors = libxml_use_internal_errors(true);
    $prevSecurity = libxml_set_external_entity_loader(static fn() => null);

    $contents = new \SimpleXMLElement($xmlContent, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOWARNING | LIBXML_NOERROR);

    libxml_disable_entity_loader($disableEntities);
    libxml_use_internal_errors($useInternalErrors);
    libxml_set_external_entity_loader($prevSecurity);

    foreach ($contents->file->body->{'trans-unit'} as $translationKey) {
        $translationId = (string) $translationKey['id'];
        $translationKeyStr = (string) $translationKey->source;

        $translationKeys[$translationId] = $translationKeyStr;
    }

    return $translationKeys;
}

function findTransUnitMismatches(array $baseTranslationKeys, array $translatedKeys): array
{
    $mismatches = [];
    foreach ($baseTranslationKeys as $translationId => $translationKey) {
        if (!isset($translatedKeys[$translationId])) {
            continue;
        }
        if ($translatedKeys[$translationId] !== $translationKey) {
            $mismatches[$translationId] = [
                'found' => $translatedKeys[$translationId],
                'expected' => $translationKey,
            ];
        }
    }
    return $mismatches;
}

function printTitle($title)
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); // ✅ prevent XSS
    echo $safeTitle . \PHP_EOL;
    echo str_repeat('=', strlen($safeTitle)) . \PHP_EOL . \PHP_EOL;
}

function printTable($translations, $verboseOutput, bool $includeCompletedLanguages)
{
    if (0 === count($translations)) {
        echo 'No translations found';
        return;
    }

    $longestLocaleNameLength = max(array_map('strlen', array_keys($translations)));

    foreach ($translations as $locale => $translation) {
        if (!$includeCompletedLanguages && $translation['is_completed']) {
            continue;
        }

        if ($translation['translated'] > $translation['total'] || count($translation['mismatches']) > 0) {
            textColorRed();
        } elseif ($translation['is_completed']) {
            textColorGreen();
        }

        // ✅ escape dynamic values
        echo sprintf(
            '|  Locale: %-'.$longestLocaleNameLength.'s  |  Translated: %2d/%2d  |  Mismatches: %d  |',
            htmlspecialchars($locale, ENT_QUOTES, 'UTF-8'),
            (int) $translation['translated'],
            (int) $translation['total'],
            count($translation['mismatches'])
        ) . \PHP_EOL;

        textColorNormal();

        $shouldBeClosed = false;
        if ($verboseOutput && count($translation['missingKeys']) > 0) {
            echo '|    Missing Translations:' . \PHP_EOL;

            foreach ($translation['missingKeys'] as $id => $content) {
                echo sprintf(
                    '|      (id=%s) %s',
                    htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($content, ENT_QUOTES, 'UTF-8')
                ) . \PHP_EOL;
            }
            $shouldBeClosed = true;
        }

        if ($verboseOutput && count($translation['mismatches']) > 0) {
            echo '|    Mismatches between trans-unit id and source:' . \PHP_EOL;

            foreach ($translation['mismatches'] as $id => $content) {
                echo sprintf(
                    '|      (id=%s) Expected: %s',
                    htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($content['expected'], ENT_QUOTES, 'UTF-8')
                ) . \PHP_EOL;
                echo sprintf(
                    '|              Found:    %s',
                    htmlspecialchars($content['found'], ENT_QUOTES, 'UTF-8')
                ) . \PHP_EOL;
            }
            $shouldBeClosed = true;
        }

        if ($shouldBeClosed) {
            echo str_repeat('-', 80) . \PHP_EOL;
        }
    }
}

function textColorGreen() { echo "\033[32m"; }
function textColorRed() { echo "\033[31m"; }
function textColorNormal() { echo "\033[0m"; }
