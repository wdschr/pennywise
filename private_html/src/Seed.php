<?php

declare(strict_types=1);

/**
 * Default categories & preset items created for every new user account,
 * so a fresh account is immediately usable rather than empty.
 */
final class Seed
{
    private const EXPENSE_CATEGORIES = [
        'Essential' => '#4f8ef7',
        'Subscriptions' => '#9b6bf2',
        'Travel' => '#28a99e',
        'Borrowing' => '#e0a72e',
        'Unplanned' => '#e0632e',
        'Luxury' => '#d94f8c',
    ];

    private const INCOME_CATEGORIES = [
        'Salary' => '#2ecc71',
        'Other Income' => '#7fd3a3',
    ];

    private const TRAVEL_PRESETS = [
        'Train Tickets' => 20.50,
        'Bus Fares' => 2.80,
        'TfL Zone 1 (Tube, single)' => 6.20,
        'TfL Zone 1 Daily Cap' => 8.90,
        'Fuel' => null,
        'Parking' => null,
    ];

    public static function forNewUser(int $userId): void
    {
        $pdo = Database::pdo();

        $insertCategory = $pdo->prepare(
            'INSERT INTO categories (owner_user_id, kind, name, color, sort_order) VALUES (:owner, :kind, :name, :color, :sort)'
        );
        $insertPreset = $pdo->prepare(
            'INSERT INTO preset_items (category_id, owner_user_id, name, default_amount, auto_carry_forward, carry_forward_day, sort_order)
             VALUES (:category_id, :owner, :name, :amount, :carry, :day, :sort)'
        );

        $sort = 0;
        foreach (self::EXPENSE_CATEGORIES as $name => $color) {
            $insertCategory->execute([
                'owner' => $userId,
                'kind' => 'expense',
                'name' => $name,
                'color' => $color,
                'sort' => $sort++,
            ]);
            $categoryId = (int) $pdo->lastInsertId();

            if ($name === 'Travel') {
                $presetSort = 0;
                foreach (self::TRAVEL_PRESETS as $presetName => $amount) {
                    $insertPreset->execute([
                        'category_id' => $categoryId,
                        'owner' => $userId,
                        'name' => $presetName,
                        'amount' => $amount,
                        'carry' => 0,
                        'day' => null,
                        'sort' => $presetSort++,
                    ]);
                }
            }
        }

        $sort = 0;
        foreach (self::INCOME_CATEGORIES as $name => $color) {
            $insertCategory->execute([
                'owner' => $userId,
                'kind' => 'income',
                'name' => $name,
                'color' => $color,
                'sort' => $sort++,
            ]);
            $categoryId = (int) $pdo->lastInsertId();

            if ($name === 'Salary') {
                $insertPreset->execute([
                    'category_id' => $categoryId,
                    'owner' => $userId,
                    'name' => 'Salary',
                    'amount' => null,
                    'carry' => 1,
                    'day' => 0, // last Friday of the month
                    'sort' => 0,
                ]);
            }
        }

        $pot = $pdo->prepare(
            "INSERT INTO savings_pots (owner_user_id, name, pot_type, color) VALUES (:owner, 'Emergency Fund', 'general', '#4f8ef7')"
        );
        $pot->execute(['owner' => $userId]);
    }
}
