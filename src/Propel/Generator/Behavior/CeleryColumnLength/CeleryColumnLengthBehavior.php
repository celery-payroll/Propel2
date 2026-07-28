<?php

namespace Propel\Generator\Behavior\CeleryColumnLength;

use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;

/**
 * Celery-specific behavior that validates text column lengths before a row is written.
 *
 * An over-long value would otherwise reach MySQL and come back as a raw
 * "SQLSTATE[22001] ... Data too long for column 'x'" error, which surfaces to the end user as
 * an unreadable SQL string. This behavior checks the length first and raises a typed exception
 * carrying the table, column, allowed length and actual length, so the application can render a
 * localised message.
 *
 * Usage in schema.xml within a [table] node:
 * <behavior name="celery_column_length"/>
 *
 * Optional parameters:
 * <behavior name="celery_column_length">
 *     <parameter name="validator" value="\Propel\Runtime\Util\ColumnLengthValidator"/>
 *     <parameter name="exclude_columns" value="[column_name_to_skip],[another]"/>
 * </behavior>
 *
 * The 'validator' parameter exists so a project can substitute its own checker; it defaults to
 * the one shipped with the package, so the generated code never references a class the package
 * does not provide.
 *
 * The behavior injects a single validator call into the generated doSave() via the preSave hook,
 * so the generated code stays small regardless of how many text columns the table has. Only
 * modified columns are checked at runtime: an unmodified value was loaded from the database and
 * therefore already fits, and re-checking it would make existing rows unsaveable if a migration
 * ever shrank a column.
 */
class CeleryColumnLengthBehavior extends Behavior
{
    //*** Default parameters value.
    protected $parameters = [
        "validator" => "\\Propel\\Runtime\\Util\\ColumnLengthValidator",
        "exclude_columns" => null,
    ];

    /**
     * Inject the length validation into the generated doSave() method, before the insert/update.
     *
     * @param \Propel\Generator\Builder\Om\AbstractOMBuilder $builder
     * @return string The PHP code to inject, or an empty string when the table has no sized text columns.
     */
    public function preSave($builder): string
    {
        $arrColumns = $this->getValidatableColumns();

        if (count($arrColumns) === 0) {
            return "";
        }

        $strTableMap = $builder->getClassNameFromBuilder($builder->getNewTableMapBuilder($this->getTable()));

        //*** Emit a fully qualified name: the generated code lives in the model's own namespace,
        //*** where a relative reference would resolve against that namespace instead of the root.
        $strValidator = "\\" . ltrim((string)$this->getParameter("validator"), "\\");
        $arrEntries = [];

        foreach ($arrColumns as $objColumn) {
            $arrEntries[] = sprintf(
                "        [%s, '%s', '%s', %d, \$this->%s],",
                $objColumn->getFQConstantName(),
                $objColumn->getName(),
                $objColumn->getPhpName(),
                (int)$objColumn->getSize(),
                $objColumn->getLowercasedName()
            );
        }

        $strEntries = implode("\n", $arrEntries);

        //*** Propel prefixes the injected block with a "// celery_column_length behavior" label itself.
        return <<<PHP
{$strValidator}::validate(
    {$strTableMap}::TABLE_NAME,
    \$this->modifiedColumns,
    [
{$strEntries}
    ]
);
PHP;
    }

    /**
     * Collect the text columns that carry a usable maximum length.
     *
     * LOB columns are skipped: their size in the schema does not describe a character limit.
     * Columns listed in the 'exclude_columns' parameter are skipped as well.
     *
     * @return array<int, \Propel\Generator\Model\Column>
     */
    protected function getValidatableColumns(): array
    {
        $arrExcluded = $this->getExcludedColumnNames();
        $arrReturn = [];

        foreach ($this->getTable()->getColumns() as $objColumn) {
            if (!$this->isValidatable($objColumn) || in_array($objColumn->getName(), $arrExcluded, true)) {
                continue;
            }

            $arrReturn[] = $objColumn;
        }

        return $arrReturn;
    }

    /**
     * Determine whether the given column is a sized, non-LOB text column.
     *
     * @param \Propel\Generator\Model\Column $objColumn The column to check.
     * @return bool True when the column value should be length checked.
     */
    protected function isValidatable(Column $objColumn): bool
    {
        return $objColumn->isTextType()
            && !$objColumn->isLobType()
            && (int)$objColumn->getSize() > 0;
    }

    /**
     * Read the 'exclude_columns' parameter as a list of column names.
     *
     * @return array<int, string>
     */
    protected function getExcludedColumnNames(): array
    {
        $strExcluded = (string)$this->getParameter("exclude_columns");

        if (trim($strExcluded) === "") {
            return [];
        }

        return array_values(array_filter(array_map("trim", explode(",", $strExcluded))));
    }
}
