<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Exception;

use Exception;

/**
 * Thrown when a value is too long for the database column it would be written to.
 *
 * Raised by Propel\Runtime\Util\ColumnLengthValidator from the generated doSave() when the
 * celery_column_length behavior is enabled, before the INSERT or UPDATE reaches the database.
 * It replaces the driver's raw "SQLSTATE[22001] ... Data too long for column 'x'" error with a
 * typed exception carrying everything a caller needs to build a readable message: the table, the
 * column (database and PHP name), the allowed length and the actual one.
 */
class ColumnValueTooLongException extends PropelException
{
    /**
     * @param string $tableName The database table being written to.
     * @param string $columnName The database column name that received the over-long value.
     * @param string $phpName The Propel PHP name of the column.
     * @param int $maxLength The maximum number of characters the column accepts.
     * @param int $actualLength The number of characters in the rejected value.
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(
        protected string $tableName,
        protected string $columnName,
        protected string $phpName,
        protected int $maxLength,
        protected int $actualLength,
        ?\Throwable $previous = null
    ) {
        $message = sprintf(
            "The value for '%s.%s' is too long: %d characters given, %d allowed.",
            $tableName,
            $columnName,
            $actualLength,
            $maxLength
        );

        Exception::__construct($message, 0, $previous);
    }

    /**
     * Get the database table being written to.
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the database column name that received the over-long value.
     *
     * @return string
     */
    public function getColumnName(): string
    {
        return $this->columnName;
    }

    /**
     * Get the Propel PHP name of the column.
     *
     * @return string
     */
    public function getPhpName(): string
    {
        return $this->phpName;
    }

    /**
     * Get the maximum number of characters the column accepts.
     *
     * @return int
     */
    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Get the number of characters in the rejected value.
     *
     * @return int
     */
    public function getActualLength(): int
    {
        return $this->actualLength;
    }
}
