<?php

namespace Propel\Generator\Behavior\CeleryEnum;

use Propel\Generator\Model\Behavior;
use Propel\Generator\Util\PhpParser;

/**
 * Celery-specific behavior that replaces the generated TableMap::getValueSets() method.
 *
 * By default, Propel maps ENUM values to integer indices (0, 1, 2, ...) in the value set array.
 * This behavior changes the mapping so that ENUM value set arrays are keyed by their
 * corresponding constant names (e.g. self::COL_STATUS_ACTIVE => self::COL_STATUS_ACTIVE),
 * allowing string-based ENUM storage and lookup instead of positional integers.
 */
class CeleryEnumBehavior extends Behavior
{
    public function tableMapFilter(&$script)
    {
        $newContent = $this->getNewSetterContent();

        $parser = new PhpParser($script, true);
        $parser->replaceMethod("getValueSets", $newContent);

        $script = $parser->getCode();
    }

    protected function getNewSetterContent(): string
    {
        $strReturn = <<<PHP


    /**
     * Gets the list of values for all ENUM and SET columns
     * @return array
     */
    public static function getValueSets(): array
    {

PHP;
        $strReturn .= "        return [";
        foreach ($this->getTable()->getColumns() as $col) {
            if ($col->isValueSetType()) {
                $strReturn .= "
            {$col->getFQConstantName()} => [
";
                foreach ($col->getValueSet() as $value) {
                    $strConst = 'self::' . $col->getConstantName() . '_' . $this->getValueSetConstant($value);
                    $strReturn .= "                {$strConst} => {$strConst},
";
                }
                $strReturn .= '            ],';
            }
        }
        $strReturn .= "
        ];
    }";

        return $strReturn;
    }

    protected function getValueSetConstant(string $value): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9_\x7f-\xff]/', '_', $value));
    }
}
