<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

/**
 * Produces the adaptive intent suggestions shown above the Ambient input.
 *
 * The chips are NOT hardcoded — the OS "listens" to its user. Suggestions blend
 * two signals:
 *  - history: the user's own recent intents (their phrasing, ranked by recency
 *    + frequency), so the system surfaces what this person actually does;
 *  - context: time-of-day + weekday/weekend rules, so a morning differs from an
 *    evening and a Saturday from a Tuesday.
 *
 * On a fresh install (no history) it falls back to gentle, generic starters
 * ("I want to listen to music", "Help me plan my day").
 *
 * Time context is supplied by the caller (the shell sends the client's local
 * hour + weekend flag) so chips match the user's wall clock regardless of the
 * server timezone. Vacation / calendar awareness is a future context factor.
 *
 * @phpstan-type Suggestion array{text: string, hint: string, source: string}
 */
#[AsService]
final class SuggestionEngine
{
    /** Cap how many distinct past intents we rank, for stable, cheap blending. */
    private const HISTORY_POOL = 8;

    #[InjectAsReadonly]
    protected OsSessionStore $session;

    /**
     * @return list<Suggestion>
     */
    public function suggest(int $hour, bool $isWeekend, int $limit = 4): array
    {
        $limit = max(1, min($limit, 6));
        $bucket = $this->bucket($hour);

        $history = $this->fromHistory();
        $context = $this->fromContext($bucket, $isWeekend);

        $out = [];
        $seen = [];
        $push = function (array $s) use (&$out, &$seen, $limit): void {
            $key = $this->normalize((string) $s['text']);
            if ($key === '' || isset($seen[$key]) || count($out) >= $limit) {
                return;
            }
            $seen[$key] = true;
            $out[] = $s;
        };

        // Lead with the user's own habits (or gentle starters on a fresh install),
        // but always keep at least one context chip when there's room for more than
        // one — so even day-one feels tied to *now* (morning ≠ evening), and a
        // seasoned user still gets a "fits right now" nudge among their habits.
        $base = $history === [] ? $this->coldStart() : $history;
        $contextSlots = $limit > 1 ? 1 : 0;
        $baseBudget = $limit - $contextSlots;

        foreach ($base as $s) {
            if (count($out) >= $baseBudget) {
                break;
            }
            $push($s);
        }
        foreach ($context as $s) {
            $push($s);
        }
        foreach ($base as $s) { // backfill any leftover slot
            $push($s);
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Mine the session log for the user's own recent, actionable intents,
     * ranked by frequency + recency. The chip reuses the user's phrasing — the
     * strongest "the system listens to you" signal.
     *
     * @return list<Suggestion>
     */
    private function fromHistory(): array
    {
        $events = $this->session->events();
        $total = count($events);
        if ($total === 0) {
            return [];
        }

        /** @var array<string, array{text: string, count: int, last: int, skill: ?string}> $agg */
        $agg = [];
        foreach ($events as $i => $event) {
            $intent = trim((string) ($event['intent'] ?? ''));
            if ($intent === '' || str_starts_with($intent, '(terminal)')) {
                continue; // terminal runs aren't natural-language chips
            }
            $key = $this->normalize($intent);
            if ($key === '') {
                continue;
            }
            if (!isset($agg[$key])) {
                $agg[$key] = ['text' => $intent, 'count' => 0, 'last' => $i, 'skill' => null];
            }
            ++$agg[$key]['count'];
            $agg[$key]['last'] = $i;             // most recent index wins
            $agg[$key]['text'] = $intent;        // freshest phrasing
            $skill = $event['skill'] ?? null;
            if (is_string($skill) && $skill !== '') {
                $agg[$key]['skill'] = $skill;
            }
        }

        if ($agg === []) {
            return [];
        }

        $ranked = array_values($agg);
        usort($ranked, function (array $a, array $b) use ($total): int {
            $sa = $a['count'] + ($a['last'] + 1) / $total; // recency as a < 1 tiebreaker
            $sb = $b['count'] + ($b['last'] + 1) / $total;

            return $sb <=> $sa;
        });

        $ranked = array_slice($ranked, 0, self::HISTORY_POOL);

        return array_map(
            static fn (array $r): array => [
                'text' => $r['text'],
                'hint' => $r['skill'] !== null ? ('you often open ' . $r['skill']) : 'you asked this before',
                'source' => 'history',
            ],
            $ranked,
        );
    }

    /**
     * Time-of-day + weekend context rules. These are the "very typical" nudges
     * that make the same screen feel different at 8am Monday vs 10pm Saturday.
     *
     * @return list<Suggestion>
     */
    private function fromContext(string $bucket, bool $isWeekend): array
    {
        $c = static fn (string $text, string $hint): array => ['text' => $text, 'hint' => $hint, 'source' => 'context'];

        if ($isWeekend) {
            $weekend = [
                $c('Plan something fun', 'it\'s the weekend'),
                $c('Help me unwind', 'weekend'),
                $c('Catch up on reading', 'weekend'),
            ];
            if ($bucket === 'morning') {
                array_unshift($weekend, $c('Ease into the day', 'weekend morning'));
            } elseif ($bucket === 'evening' || $bucket === 'night') {
                array_unshift($weekend, $c('Wind down with some music', 'weekend evening'));
            }

            return $weekend;
        }

        return match ($bucket) {
            'morning' => [
                $c('Help me plan my day', 'good morning'),
                $c('What\'s on my schedule?', 'morning'),
                $c('Brief me on what matters today', 'morning'),
            ],
            'midday' => [
                $c('Where did I leave off?', 'midday'),
                $c('Take a short break', 'midday'),
            ],
            'afternoon' => [
                $c('Pick up where I left off', 'afternoon'),
                $c('What\'s left for today?', 'afternoon'),
            ],
            'evening' => [
                $c('Wind down with some music', 'good evening'),
                $c('Plan tomorrow', 'evening'),
                $c('Recap my day', 'evening'),
            ],
            default => [ // night
                $c('Set a gentle reminder for tomorrow', 'late'),
                $c('Wind down', 'night'),
            ],
        };
    }

    /**
     * @return list<Suggestion>
     */
    private function coldStart(): array
    {
        $g = static fn (string $text): array => ['text' => $text, 'hint' => 'try me', 'source' => 'cold'];

        return [
            $g('I want to listen to music'),
            $g('Help me plan my day'),
            $g('Write a quick note'),
            $g('What can you do?'),
        ];
    }

    private function bucket(int $hour): string
    {
        if ($hour < 0 || $hour > 23) {
            $hour = (int) date('G'); // server-time fallback when the client omits it
        }

        return match (true) {
            $hour >= 5 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 14 => 'midday',
            $hour >= 14 && $hour < 18 => 'afternoon',
            $hour >= 18 && $hour < 22 => 'evening',
            default => 'night',
        };
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($text)));
    }
}
