<?php

declare(strict_types=1);

/**
 * Carries forward "auto carry forward" preset items (subscriptions,
 * salary, benefits, etc.) into a given month. Expense presets always
 * land on the 1st of the month - that choice isn't exposed to the user.
 * Income presets pick their own recurrence:
 *   - Monthly (the default), landing on a chosen day: NULL for the 1st,
 *     0 for the last Friday of the month (a typical month-end payday,
 *     so a salary preset generated for October shows up in October's
 *     budget even though the real-world payment might land right at
 *     the end of September), or a specific day 1-31 (clamped to the
 *     month's actual length).
 *   - Every 4 weeks from a known anchor date (e.g. a benefit payment
 *     that isn't tied to the calendar month) - every occurrence that
 *     falls inside the target month is generated, which is usually one
 *     but is sometimes zero or two, since 4 weeks doesn't line up with
 *     a calendar month.
 */
final class Recurring
{
    /**
     * @return array{count:int, items:array} number and names of items generated
     */
    public static function generateForCurrentMonth(int $userId): array
    {
        return self::generateForMonth($userId, date('Y-m-01'));
    }

    /**
     * From this day of the month onward, the default view switches to
     * next month - a few days ahead of the last-Friday payday, so there's
     * time to set the budget up and adjust standing orders before it pays out.
     */
    private const DEFAULT_MONTH_SWITCH_DAY = 20;

    /**
     * The month a "which budget am I looking at" page should default to
     * when no month is explicitly chosen. From the switch day onward,
     * that's next month - matching the point at which you'd naturally
     * sit down to set it up ahead of payday. Before that, it's just the
     * current month.
     */
    public static function defaultBudgetMonth(?string $today = null): string
    {
        $todayTs = $today !== null ? strtotime($today) : time();
        $currentMonthStart = date('Y-m-01', $todayTs);

        if ((int) date('j', $todayTs) >= self::DEFAULT_MONTH_SWITCH_DAY) {
            return date('Y-m', strtotime('+1 month', strtotime($currentMonthStart)));
        }

        return date('Y-m', strtotime($currentMonthStart));
    }

    /**
     * Resolves a monthly preset's carry_forward_day into an actual date
     * within the given month.
     */
    private static function resolveMonthlyDate(?int $carryForwardDay, string $monthStart): string
    {
        if ($carryForwardDay === null) {
            return $monthStart;
        }

        if ($carryForwardDay === 0) {
            return date('Y-m-d', strtotime('last friday of ' . date('F Y', strtotime($monthStart))));
        }

        $lastDayOfMonth = (int) date('t', strtotime($monthStart));
        $day = min($carryForwardDay, $lastDayOfMonth);

        return sprintf('%s-%02d', date('Y-m', strtotime($monthStart)), $day);
    }

    /**
     * Every date that is exactly N*28 days from the anchor and falls
     * within [monthStart, monthEnd]. Usually one date, but a calendar
     * month can contain zero or two 28-day occurrences since the cycle
     * doesn't line up with month boundaries.
     *
     * Uses DateInterval (calendar-day) arithmetic rather than adding
     * raw seconds to a timestamp - the latter silently loses or gains a
     * day whenever a step crosses a UK clock change.
     *
     * @return string[]
     */
    private static function occurrencesEvery4Weeks(string $anchorDate, string $monthStart, string $monthEnd): array
    {
        $interval = new \DateInterval('P28D');
        $anchor = new \DateTimeImmutable($anchorDate);
        $rangeStart = new \DateTimeImmutable($monthStart);
        $rangeEnd = new \DateTimeImmutable($monthEnd);

        $stepsToStart = (int) floor(($rangeStart->getTimestamp() - $anchor->getTimestamp()) / 86400 / 28) - 1;
        $candidate = $stepsToStart >= 0
            ? $anchor->add(new \DateInterval('P' . ($stepsToStart * 28) . 'D'))
            : $anchor->sub(new \DateInterval('P' . (-$stepsToStart * 28) . 'D'));

        $dates = [];
        while ($candidate <= $rangeEnd) {
            if ($candidate >= $rangeStart) {
                $dates[] = $candidate->format('Y-m-d');
            }
            $candidate = $candidate->add($interval);
        }

        return $dates;
    }

    /**
     * @return string[] the dates within this month this item is due on
     */
    private static function occurrenceDates(array $item, string $monthStart, string $monthEnd): array
    {
        if ($item['carry_forward_interval'] === 'every_4_weeks') {
            if ($item['carry_forward_anchor_date'] === null) {
                return [];
            }
            return self::occurrencesEvery4Weeks($item['carry_forward_anchor_date'], $monthStart, $monthEnd);
        }

        $carryForwardDay = $item['carry_forward_day'] !== null ? (int) $item['carry_forward_day'] : null;
        return [self::resolveMonthlyDate($carryForwardDay, $monthStart)];
    }

    public static function generateForMonth(int $userId, string $monthStart): array
    {
        $pdo = Database::pdo();
        $monthStart = date('Y-m-01', strtotime($monthStart));
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $items = $pdo->prepare(
            'SELECT pi.*, c.kind AS category_kind FROM preset_items pi
             JOIN categories c ON c.id = pi.category_id
             WHERE pi.owner_user_id = :owner AND pi.auto_carry_forward = 1 AND pi.is_active = 1'
        );
        $items->execute(['owner' => $userId]);
        $presetItems = $items->fetchAll();

        if ($presetItems === []) {
            return ['count' => 0, 'items' => []];
        }

        $existing = $pdo->prepare(
            'SELECT preset_item_id, txn_date FROM transactions
             WHERE owner_user_id = :owner AND source = "recurring"
             AND txn_date BETWEEN :start AND :end AND preset_item_id IS NOT NULL'
        );
        $existing->execute(['owner' => $userId, 'start' => $monthStart, 'end' => $monthEnd]);
        $alreadyGenerated = [];
        foreach ($existing->fetchAll() as $row) {
            $alreadyGenerated[$row['preset_item_id'] . ':' . $row['txn_date']] = true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO transactions (owner_user_id, kind, category_id, preset_item_id, description, amount, txn_date, source, recurring_origin_item_id)
             VALUES (:owner, :kind, :category_id, :preset_item_id, :description, :amount, :txn_date, "recurring", :origin)'
        );

        $generated = [];
        foreach ($presetItems as $item) {
            if ($item['default_amount'] === null) {
                continue;
            }

            $itemId = (int) $item['id'];
            $kind = $item['category_kind'] === 'income' ? 'income' : 'expense';

            foreach (self::occurrenceDates($item, $monthStart, $monthEnd) as $txnDate) {
                if (isset($alreadyGenerated[$itemId . ':' . $txnDate])) {
                    continue;
                }

                $insert->execute([
                    'owner' => $userId,
                    'kind' => $kind,
                    'category_id' => $item['category_id'],
                    'preset_item_id' => $itemId,
                    'description' => $item['name'],
                    'amount' => $item['default_amount'],
                    'txn_date' => $txnDate,
                    'origin' => $itemId,
                ]);

                $generated[] = $item['name'];
            }
        }

        if ($generated !== []) {
            Audit::log($userId, 'recurring_generated', 'transactions', null, ['month' => $monthStart, 'items' => $generated]);
        }

        return ['count' => count($generated), 'items' => $generated];
    }
}
