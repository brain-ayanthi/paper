<?php

namespace App\Actions\Languages;

use App\Models\Language;
use App\Models\Translation;
use App\Services\Excel\SpreadsheetReader;
use App\Services\Translation\TranslationCatalog;
use App\Support\Translation\DatabaseMergeLoader;

/**
 * Read a translation `.xlsx`/`.csv` (as produced by {@see ExportTranslations})
 * and upsert the `Translation` column into the `translations` table for
 * the language. Empty translations are skipped (the key keeps its English
 * default). Header columns are matched by name, so column order is
 * forgiving. Returns a summary; bumps the translation cache version.
 *
 * @return array{imported: int, skipped: int, errors: int}
 */
class ImportTranslations
{
    public function __construct(
        private SpreadsheetReader $reader,
        private ?TranslationCatalog $catalog = null,
    ) {}

    public function __invoke(Language $language, string $path): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;
        $map      = null;
        $rows     = [];

        foreach ($this->reader->rows($path) as $i => $cells) {
            if ($i === 0) {
                $map = $this->columnMap($cells);
                continue;
            }
            if ($map === null) {
                break;
            }

            $key   = $cells[$map['key']] ?? '';
            $group = $cells[$map['group']] ?? '';
            $value = $cells[$map['translation']] ?? '';

            if ($key === '' || $group === '') {
                $errors++;
                continue;
            }
            if ($value === '') {
                $skipped++;
                continue;
            }

            // Only keys the product actually ships (SEC #19 / defect #94).
            // Any group+key pair was previously accepted and stored, so an
            // import could seed arbitrary rows — junk at best, and a payload
            // aimed at a future unescaped render at worst. Anything unknown is
            // counted as an error rather than silently kept.
            if (! $this->isKnownKey($group, $key)) {
                $errors++;
                continue;
            }

            $rows[] = [
                'locale'     => $language->code,
                'group'      => $group,
                'key'        => $key,
                'value'      => $value,
                'updated_at' => now(),
                'created_at' => now(),
            ];
            $imported++;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Translation::query()->upsert($chunk, ['locale', 'group', 'key'], ['value', 'updated_at']);
        }

        DatabaseMergeLoader::bumpVersion();

        do_action('language.translations_imported', $language, $imported);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Resolve column indexes from the header row by name (case-insensitive),
     * falling back to the export's fixed order [Key, Section, English, Translation].
     *
     * @param array<int, string> $header
     * @return array{key: int, group: int, translation: int}|null
     */
    /** @var array<string, bool>|null lazily-built set of "group.key" the app ships */
    private ?array $knownKeys = null;

    /**
     * Is this a key the base English language files actually define?
     *
     * Built once from `lang/en/*.php` and flattened to dotted paths, matching
     * how the export lists them — so a round-trip of an exported file always
     * validates, while invented keys do not.
     */
    private function isKnownKey(string $group, string $key): bool
    {
        if ($this->knownKeys === null) {
            $this->knownKeys = [];

            foreach (($this->catalog ?? app(TranslationCatalog::class))->keys() as $row) {
                $this->knownKeys[$row['group'].'.'.$row['key']] = true;
            }
        }

        return isset($this->knownKeys[$group.'.'.$key]);
    }

    private function columnMap(array $header): ?array
    {
        $lower = array_map(fn ($h) => strtolower(trim($h)), $header);
        $find  = fn (string $name, int $default) => ($idx = array_search($name, $lower, true)) !== false ? $idx : $default;

        return [
            'key'         => $find('key', 0),
            'group'       => $find('section', 1),
            'translation' => $find('translation', 3),
        ];
    }
}
