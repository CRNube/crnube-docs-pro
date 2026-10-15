<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

/**
 * Plain text of Word and Excel files, read locally (they are zip files of XML), so these
 * documents cost nothing to prepare.
 */
final class OfficeText
{
    private const MAX_XML = 20 * 1024 * 1024;

    public static function docx(string $path): string
    {
        $xml = self::entry($path, 'word/document.xml');
        if ($xml === '') {
            return '';
        }
        // Paragraphs and table cells become lines and " | " separators.
        $xml = (string) preg_replace(['#</w:p>#', '#</w:tc>#', '#<w:tab/>#', '#<w:br[^>]*/>#'], ["\n", ' | ', "\t", "\n"], $xml);
        return self::clean(html_entity_decode(wp_strip_all_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    public static function xlsx(string $path): string
    {
        $shared  = [];
        $strings = self::entry($path, 'xl/sharedStrings.xml');
        if ($strings !== '' && preg_match_all('#<si>(.*?)</si>#s', $strings, $m)) {
            foreach ($m[1] as $si) {
                $shared[] = html_entity_decode(wp_strip_all_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $sheet)) {
                continue;
            }
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || $stat['size'] > self::MAX_XML) {
                continue;
            }
            $xml   = (string) $zip->getFromIndex($i);
            $out[] = '[[hoja ' . $sheet[1] . ']]';
            preg_match_all('#<row[^>]*>(.*?)</row>#s', $xml, $rows);
            foreach ($rows[1] as $row) {
                preg_match_all('#<c([^>]*)>(.*?)</c>#s', $row, $cells, PREG_SET_ORDER);
                $values = [];
                foreach ($cells as $cell) {
                    $value = preg_match('#<v>(.*?)</v>#s', $cell[2], $v) ? $v[1] : (preg_match('#<t[^>]*>(.*?)</t>#s', $cell[2], $t) ? $t[1] : '');
                    if (str_contains($cell[1], 't="s"')) {
                        $value = $shared[(int) $value] ?? '';
                    }
                    $values[] = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
                if (implode('', $values) !== '') {
                    $out[] = implode(' | ', $values);
                }
            }
        }
        $zip->close();
        return self::clean(implode("\n", $out));
    }

    private static function entry(string $path, string $name): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $stat = $zip->statName($name);
        // A small zip that expands to gigabytes is not read.
        $data = is_array($stat) && $stat['size'] <= self::MAX_XML ? (string) $zip->getFromName($name) : '';
        $zip->close();
        return $data;
    }

    private static function clean(string $text): string
    {
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
