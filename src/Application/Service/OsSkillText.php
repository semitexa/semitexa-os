<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Domain\Model\SkillEntry;

/**
 * What a skill calls itself, in the console's language.
 *
 * Every app in the launcher described itself in English, on a console the
 * person had set to Ukrainian. The text lives in a PHP attribute — evaluated
 * once, cached, and read long before anyone knows which locale is being served
 * — so it cannot be translated where it is written.
 *
 * So it is not moved: the attribute's English stays, as the fallback, and the
 * translation is looked up by a key DERIVED from the skill's name. Nothing is
 * added for a skill author to keep in sync, a skill nobody translates reads
 * exactly as it does today, and an install with no catalogs behaves
 * byte-identically. The same bargain {@see OsReplies} already makes for chat.
 *
 * Keys are `os.skills.<name>.summary` and `.use_when`. The name is lowercased
 * and its separators normalised, so `tic-tac-toe` and `TicTacToe` cannot end up
 * as two different keys for one app.
 */
final class OsSkillText
{
    public static function summary(SkillEntry $skill): string
    {
        return OsReplies::resolve(self::key($skill->name, 'summary'), [], $skill->summary);
    }

    public static function useWhen(SkillEntry $skill): string
    {
        return OsReplies::resolve(self::key($skill->name, 'use_when'), [], $skill->useWhen);
    }

    /** `os.skills.tic-tac-toe.summary` — `os.` is the module the catalog is filed under. */
    private static function key(string $name, string $part): string
    {
        // Split lower-to-upper first: 'TicTacToe' has no separator to normalise,
        // so lowercasing alone made it 'tictactoe' while the catalog carries
        // 'tic-tac-toe'. The author would have written the line and seen no
        // translation, with nothing to explain it.
        $slug = (string) preg_replace('/([a-z0-9])([A-Z])/u', '$1-$2', trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', strtolower($slug));

        return 'os.skills.' . trim($slug, '-') . '.' . $part;
    }
}
