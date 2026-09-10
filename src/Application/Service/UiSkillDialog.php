<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Domain\Model\SkillEntry;

/**
 * Turning a proposed UI skill into the window that opens for it.
 *
 * Three decisions that belong together and belong to neither the planner nor
 * the loop: which of the planner's arguments may reach the entry URL, what that
 * URL becomes, and what the window is called. They live here rather than on
 * {@see SkillLoopRunner} because none of them touches its state — and because
 * the runner is already the largest class in the package, which the structural
 * budget said out loud when these were added to it.
 */
final class UiSkillDialog
{
    /**
     * The planner's arguments, narrowed to the ones this skill actually declares.
     *
     * An allowlist, not a pass-through. The entry is a URL the OS opens in a
     * window, so anything reaching it is being appended to that app's own query
     * string; a planner is a language model and its proposed argument names are
     * a suggestion, not a contract. Declaration order is the iteration order, so
     * the same plan always produces the same URL.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, string>
     */
    public static function query(SkillEntry $entry, array $arguments): array
    {
        $applied = [];
        foreach (array_keys($entry->inputs) as $name) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }
            $value = $arguments[$name];
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            // A flag reaching a URL has to be readable on the other side; '1'/'0'
            // survives a plain string comparison where 'true'/'' does not.
            $applied[$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $applied;
    }

    /** @param array<string, string> $query */
    public static function entryUrl(?string $entry, array $query): ?string
    {
        // An argument-less UI skill — Notes, Calendar, Terminal — opens at exactly
        // the URL it always did, untouched.
        if ($entry === null || $query === []) {
            return $entry;
        }

        return $entry
            . (str_contains($entry, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Whether a window onto this exact thing is already open.
     *
     * Same skill AND same entry. Two different pages of the same editor are two
     * dialogs, not a duplicate — the guard exists to stop a second window onto
     * the SAME thing, and once a UI skill can carry arguments the skill name
     * alone stopped saying which thing.
     *
     * @param list<array<string, mixed>> $open
     */
    public static function isAlreadyOpen(array $open, string $skill, ?string $entryUrl): bool
    {
        foreach ($open as $dialog) {
            if (($dialog['skill'] ?? null) === $skill && ($dialog['entry'] ?? null) === $entryUrl) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the window is called.
     *
     * Opening five pages from chat used to give five windows called 'Content',
     * which is the app's name and not the place's — a person switching between
     * them had nothing to switch BY. The shell path already names the window
     * after the place (OpenDialogHandler resolves the ref through the graph);
     * the chat path cannot, because at this point the argument is still what the
     * person said rather than a resolved record. So it shows exactly that, next
     * to the app it belongs to, instead of claiming a title it has not looked up.
     *
     * The first declared input is the label: declaration order is the contract
     * {@see query()} already relies on, and a skill lists what identifies a
     * record first.
     *
     * @param array<string, string> $applied
     */
    public static function title(string $skill, array $applied): string
    {
        // Not `?: ''` — that treats the string '0' as absent, and query()
        // serialises a false flag to exactly that. 'Content — 0' became
        // 'Content'.
        $first = $applied === [] ? '' : trim((string) reset($applied));
        if ($first === '') {
            return $skill;
        }

        if (mb_strlen($first) > 40) {
            $first = mb_substr($first, 0, 39) . '…';
        }

        return $skill . ' — ' . $first;
    }
}
