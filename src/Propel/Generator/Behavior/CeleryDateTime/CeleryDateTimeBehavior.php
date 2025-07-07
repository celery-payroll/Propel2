<?php

namespace Propel\Generator\Behavior\CeleryDateTime;

use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Util\PhpParser;

class CeleryDateTimeBehavior extends Behavior
{
    public function objectFilter(&$script)
    {
        $table = $this->getTable();
        foreach ($table->getColumns() as $column) {
            if (!$this->isTemporal($column)) {
                continue;
            }
            $phpName = $column->getPhpName();
            $newContent = $this->getNewGetterContent($column);
            $parser = new PhpParser($script, true);
            $parser->replaceMethod("get{$phpName}", $newContent);
            $script = $parser->getCode();

            $newContent = $this->getNewSetterContent($column);
            $parser = new PhpParser($script, true);
            $parser->replaceMethod("set{$phpName}", $newContent);
            $script = $parser->getCode();
        }
    }

    public function getNewGetterContent(Column $column)
    {
        $columnName = $column->getName();
        $phpName = $column->getPhpName();
        $isDate = $column->getType() === PropelTypes::DATE;

        if ($isDate) {
            $comment = "Custom Date getter for {$columnName} (no timezone conversion)";
            $timezoneCode = '';
            $errorMsg = 'date';
        } else {
            $comment = "Custom DateTime getter for {$columnName}";
            $timezoneCode = "\n            \$dt->setTimeZone(new \\DateTimeZone(date_default_timezone_get()));";
            $errorMsg = 'datetime';
        }

        return <<<PHP

    /**
     * {$comment}
     */
    public function get{$phpName}(?string \$format = 'Y-m-d')
    {
        if (\$this->{$columnName} === null) {
            return null;
        }

        if (\$this->{$columnName} === '0000-00-00') {
            return null;
        }

        try {
            \$dt = clone \$this->{$columnName};{$timezoneCode}
        } catch (\Exception \$x) {
            throw new \Propel\Runtime\Exception\PropelException("Could not convert internal {$errorMsg} value: " . var_export(\$this->{$columnName}, true), 0, \$x);
        }

        if (\$format === null) {
            return \$dt;
        }

        if (\$format === 'carbon') {
            try {
                return new \Carbon\Carbon(\$dt);
            } catch (\Exception \$x) {
                throw new \Propel\Runtime\Exception\PropelException("Carbon conversion failed.");
            }
        }

        if (strpos(\$format, '%') !== false) {
            throw new \Propel\Runtime\Exception\PropelException("strftime format is not supported. Use a date() format.");
        }

        return \$dt->format(\$format);
    }

PHP;
    }

    /**
     * Determines if the given column is of a temporal type.
     *
     * This method checks the type of the specified column to determine if it is one of the
     * temporal data types: DATE, DATETIME, TIME, or TIMESTAMP.
     *
     * @param \Propel\Generator\Model\Column $column The column to check.
     * @return bool True if the column is temporal, false otherwise.
     */
    protected function isTemporal(\Propel\Generator\Model\Column $column): bool
    {
        $type = $column->getType();

        return (
            $type === PropelTypes::DATE
            || $type === PropelTypes::DATETIME
            || $type === PropelTypes::TIME
            || $type === PropelTypes::TIMESTAMP
        );
    }

    public function getNewSetterContent(Column $column)
    {
        $clo = $column->getLowercasedName();
        $orNull = $column->isNotNull() ? '' : '|null';
        $columnName = $column->getName();
        $phpName = $column->getPhpName();
        $format = $this->getFormat($column);
        $isDate = $column->getType() === PropelTypes::DATE;

        if ($isDate) {
            $comment = "Sets the value of [$clo] column to a normalized version of the date value specified (no timezone conversion).";
            $dateTimeConversion = <<<'PHP'
        if ($v instanceof \DateTimeInterface) {
            $v = $v->format('Y-m-d');
        }

PHP;
            $timezoneArg = 'null';
        } else {
            $comment = "Sets the value of [$clo] column to a normalized version of the date/time value specified.";
            $dateTimeConversion = '';
            $timezoneArg = "new \\DateTimeZone('America/Curacao')";
        }

        return <<<PHP


    /**
     * {$comment}
     * {$column->getDescription()}
     * @param string|integer|\DateTimeInterface{$orNull} \$v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return \$this The current object (for fluent API support)
     */
    public function set{$phpName}(\$v)
    {
        {$dateTimeConversion}\$dt = PropelDateTime::newInstance(\$v, {$timezoneArg}, 'DateTime');
        if (\$this->{$columnName} !== null || \$dt !== null) {
            if (\$this->{$columnName} === null || \$dt === null || \$dt->format("{$format}") !== \$this->{$columnName}->format("{$format}")) {
                \$this->{$columnName} = \$dt === null ? null : clone \$dt;
                \$this->modifiedColumns[{$column->getFQConstantName()}] = true;
            }
        } // if either are not null

        return \$this;
    }
PHP;
    }

    protected function getFormat(Column $column): string
    {
        switch ($column->getType()) {
            case 'DATE':
                $format = 'Y-m-d';

                break;
            case 'TIME':
                $format = 'H:i:s.u';

                break;
            default:
                $format = 'Y-m-d H:i:s.u';
        }

        return $format;
    }
}
