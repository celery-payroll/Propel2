<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Util;

use Propel\Runtime\Exception\ColumnValueTooLongException;

/**
 * Validates text column lengths before a model is written to the database.
 *
 * Called from the generated doSave() by the celery_column_length behavior. Keeping the logic here
 * rather than inlining it per column keeps the generated model code to a single call, whatever the
 * number of text columns on the table.
 */
class ColumnLengthValidator
{
    /**
     * Check every supplied column value against its maximum length.
     *
     * Only modified columns are checked. An unmodified value was loaded from the database and
     * therefore already fits — re-checking it would make existing rows unsaveable after a
     * migration shrank a column, even when the caller never touched that column.
     *
     * Length is measured in characters rather than bytes, because a VARCHAR size counts characters.
     *
     * @param string $tableName The database table being written to.
     * @param array<string, bool> $modifiedColumns The model's modifiedColumns map, keyed by column constant.
     * @param array<int, array{0: string, 1: string, 2: string, 3: int, 4: mixed}> $columns
     *        One entry per column: [fully qualified column constant, database name, PHP name,
     *        maximum length, current value].
     *
     * @throws \Propel\Runtime\Exception\ColumnValueTooLongException When a value exceeds its column length.
     *
     * @return void
     */
    public static function validate(string $tableName, array $modifiedColumns, array $columns): void
    {
        foreach ($columns as [$constant, $columnName, $phpName, $maxLength, $value]) {
            if ($value === null || !isset($modifiedColumns[$constant])) {
                continue;
            }

            $actualLength = mb_strlen((string)$value);

            if ($actualLength <= $maxLength) {
                continue;
            }

            throw new ColumnValueTooLongException($tableName, $columnName, $phpName, $maxLength, $actualLength);
        }
    }
}
